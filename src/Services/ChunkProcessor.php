<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Data\RowFailure;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Definitions\Reference;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Events\ImportChunkCompleted;
use Nexus\ImportExport\Events\ImportProgressUpdated;
use Nexus\ImportExport\Exceptions\ChunkLeaseUnavailable;
use Nexus\ImportExport\Exceptions\ImportCancelledSignal;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Exceptions\RejectRow;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Models\ImportStagedRow;
use RuntimeException;

final class ChunkProcessor
{
    public function __construct(
        private readonly ChunkFileStore $chunkFiles,
        private readonly ImportDefinitionRegistry $definitions,
        private readonly ValidatorFactory $validator,
        private readonly ReferenceResolver $references,
        private readonly RowFailureRecorder $failureRecorder,
        private readonly ChunkCompletion $completion,
        private readonly ChunkLeaseManager $leases,
        private readonly BusinessKeyLockManager $keyLocks,
        private readonly ConnectionGuard $connections,
        private readonly DatabaseManager $database,
    ) {}

    public function handle(ImportChunk $chunk, string $leaseToken): void
    {
        $started = microtime(true);
        $import = $chunk->import()->firstOrFail();
        $this->assertNotCancelled($import);
        $definition = $this->definitions->make($import->type);
        if ($definition instanceof DataDefinition) {
            $definition->inContext($import->context ?? []);
        }
        if ($definition->version() !== $import->definition_version) {
            throw new RuntimeException('Definition version changed.');
        }
        $context = new ImportContext(
            $import->id,
            $import->actor_type,
            $import->actor_id,
            $import->context ?? [],
            $import->options ?? [],
        );

        $envelopes = iterator_to_array(
            $this->chunkFiles->read($chunk->disk, $chunk->file_path, $chunk->checksum),
            false,
        );

        if (count($envelopes) !== $chunk->total_rows) {
            throw new RuntimeException('Prepared chunk row count does not match its metadata.');
        }

        [$candidates, $failures, $sourceSkipped] = $this->validateRows($envelopes, $definition, $context);
        $validationDone = microtime(true);
        $referenceDefinitions = $definition->references();
        $resolved = $this->references->resolve(
            $referenceDefinitions,
            array_column($candidates, 'source'),
        );
        $relationDone = microtime(true);
        $transformed = [];

        foreach ($candidates as $candidate) {
            $referenceFailure = $this->missingReference(
                $candidate['row_number'],
                $candidate['source'],
                $referenceDefinitions,
                $resolved,
            );
            if ($referenceFailure !== null) {
                $failures[] = $referenceFailure;

                continue;
            }

            try {
                $payload = $definition->transform($candidate['source'], $resolved, $context);
            } catch (RejectRow $exception) {
                if ($exception->errorCode === 'skip_row') {
                    $sourceSkipped++;

                    continue;
                }
                $failures[] = new RowFailure(
                    $candidate['row_number'],
                    $exception->column,
                    $exception->errorCode,
                    $exception->getMessage(),
                    $candidate['source'],
                );

                continue;
            }
            $this->assertCompleteBusinessKey($payload, $definition);

            $transformed[] = [
                'row_number' => $candidate['row_number'],
                'source' => $candidate['source'],
                'payload' => $payload,
                'key_hash' => $definition->targetBusinessKeyHash($payload),
            ];
        }

        $target = $definition->target();
        $connection = $target === null
            ? $this->database->connection(config('bulk-imports.database.connection'))->getName()
            : $this->connections->assertMetadataAndTargetMatch($target->connectionName($context));
        if (! $this->leases->heartbeat($chunk->id, $leaseToken)) {
            throw new ChunkLeaseUnavailable('The chunk lease was lost before its write transaction.');
        }

        $stats = $this->database->connection($connection)->transaction(function () use (
            $chunk,
            $leaseToken,
            $import,
            $definition,
            $context,
            $transformed,
            $failures,
            $sourceSkipped,
            $envelopes,
        ): array {
            $attemptFailures = $failures;
            $this->assertNotCancelled($import->fresh());

            $succeeded = 0;
            $skipped = $sourceSkipped;
            $inserted = $updatedRows = $wouldInsert = $wouldUpdate = 0;
            $staged = 0;

            if ($definition->mode() === ImportMode::ATOMIC) {
                $this->stageRows($import, $chunk, $transformed);
                $staged = count($transformed);
            } else {
                [$writeRows, $duplicateFailures, $preWriteSkipped, $lock] = $this->filterDatabaseDuplicates(
                    $import,
                    $definition,
                    $context,
                    $transformed,
                );
                $attemptFailures = [...$attemptFailures, ...$duplicateFailures];
                $skipped += $preWriteSkipped;

                try {
                    foreach ($writeRows as $row) {
                        if ($row['existed'] ?? false) {
                            $wouldUpdate++;
                        } else {
                            $wouldInsert++;
                        }
                    }
                    $dryRun = (bool) $context->option('dry_run', false);
                    $result = $dryRun ? BatchWriteResult::allSucceeded(count($writeRows)) : $definition->handleBatch(array_column($writeRows, 'payload'), $context);
                    if ($result->succeeded + $result->skipped !== count($writeRows)) {
                        throw new ImportConfigurationException(
                            $definition::class.'::handleBatch() counters must account for every supplied row.',
                        );
                    }
                    if (! $dryRun) {
                        $inserted = $wouldInsert;
                        $updatedRows = $wouldUpdate;
                        $wouldInsert = $wouldUpdate = 0;
                    }
                    $succeeded += $result->succeeded;
                    $skipped += $result->skipped;
                } finally {
                    if ($lock !== null) {
                        $this->keyLocks->release(
                            $lock['connection'],
                            $lock['scope'],
                            $lock['hashes'],
                            $import->id,
                        );
                    }
                }
            }

            $sourceMap = [];
            foreach ($envelopes as $envelope) {
                $sourceMap[$envelope['row_number']] = $envelope;
            }
            $attemptFailures = array_map(static function (RowFailure $failure) use ($sourceMap): RowFailure {
                $source = $sourceMap[$failure->rowNumber];

                return new RowFailure($failure->rowNumber, $failure->column, $failure->code, $failure->message, $source['raw'] ?? $failure->row,
                    $source['sheet'] ?? null, $source['sheet_row'] ?? $failure->rowNumber, $source['data']);
            }, $attemptFailures);
            $this->failureRecorder->record($import->id, $chunk->id, $attemptFailures);
            $failed = count(array_unique(array_map(
                static fn (RowFailure $failure): int => $failure->rowNumber,
                $attemptFailures,
            )));

            if ($definition->mode() === ImportMode::PARTIAL && $succeeded + $failed + $skipped !== $chunk->total_rows) {
                throw new RuntimeException('Chunk accounting invariant failed. Every row must succeed, fail, or be skipped exactly once.');
            }
            if ($definition->mode() === ImportMode::ATOMIC && $staged + $failed !== $chunk->total_rows) {
                throw new RuntimeException('Atomic staging accounting invariant failed.');
            }

            $completed = $this->completion->complete(
                $chunk,
                $leaseToken,
                $succeeded,
                $failed,
                $skipped,
                $staged,
                $inserted, $updatedRows, $wouldInsert, $wouldUpdate,
            );

            return compact('completed', 'succeeded', 'failed', 'skipped', 'staged');
        }, attempts: 3);

        Log::info('import.chunk.completed', [
            'operation' => 'import', 'import_id' => $import->id, 'definition' => $import->definition, 'chunk_id' => $chunk->id,
            'rows' => $chunk->total_rows, 'duration_seconds' => microtime(true) - $started,
            'rows_per_second' => $chunk->total_rows / max(0.001, microtime(true) - $started),
            'validation_seconds' => $validationDone - $started, 'relation_seconds' => $relationDone - $validationDone,
            'write_seconds' => microtime(true) - $relationDone, 'attempt' => $chunk->attempts, 'memory_bytes' => memory_get_usage(true),
        ]);
        ImportChunkCompleted::dispatch($stats['completed'], $context->context);
        ImportProgressUpdated::dispatch($import->refresh());
    }

    /**
     * @param  list<array{row_number:int,data:array<string,mixed>,business_key_hash:?string,duplicate_of_row:?int}>  $envelopes
     * @return array{list<array{row_number:int,source:array<string,mixed>}>,list<RowFailure>,int}
     */
    private function validateRows(array $envelopes, ImportDefinition $definition, ImportContext $context): array
    {
        $candidates = [];
        $failures = [];
        $skipped = 0;
        $rules = $definition->rules();

        foreach ($envelopes as $envelope) {
            if (($error = $envelope['normalization_error'] ?? null) !== null) {
                $failures[] = new RowFailure($envelope['row_number'], $error['column'], $error['code'], $error['message'], $envelope['raw'] ?? $envelope['data']);

                continue;
            }
            if ($envelope['duplicate_of_row'] !== null) {
                if ($definition instanceof DataDefinition && $definition->duplicateStrategy() !== DuplicateStrategy::ERROR) {
                    $skipped++;

                    continue;
                }
                $failures[] = new RowFailure(
                    $envelope['row_number'],
                    implode(',', $definition->uniqueBy()),
                    'duplicate_in_file',
                    "Business key duplicates source row {$envelope['duplicate_of_row']}.",
                    $envelope['data'],
                );

                continue;
            }

            $validator = $this->validator->make($envelope['data'], $rules);
            if ($validator->fails()) {
                $failedRules = $validator->failed();
                foreach ($validator->errors()->getMessages() as $column => $messages) {
                    $rule = array_key_first($failedRules[$column] ?? []) ?? 'invalid';
                    foreach ($messages as $message) {
                        $failures[] = new RowFailure(
                            $envelope['row_number'],
                            $column,
                            'validation_'.Str::snake((string) $rule),
                            $message,
                            $envelope['data'],
                        );
                    }
                }

                continue;
            }

            $candidates[] = ['row_number' => $envelope['row_number'], 'source' => $envelope['data']];
        }

        return [$candidates, $failures, $skipped];
    }

    /** @param array<string,mixed> $row @param list<Reference> $definitions */
    private function missingReference(
        int $rowNumber,
        array $row,
        array $definitions,
        ResolvedReferences $resolved,
    ): ?RowFailure {
        foreach ($definitions as $reference) {
            $value = $row[$reference->sourceColumn] ?? null;
            if ($value === null || trim((string) $value) === '') {
                if ($reference->required) {
                    return new RowFailure(
                        $rowNumber,
                        $reference->sourceColumn,
                        'reference_required',
                        "Reference value [{$reference->sourceColumn}] is required.",
                        $row,
                    );
                }

                continue;
            }
            if ($reference->required && ! $resolved->has($reference->name, $value)) {
                return new RowFailure(
                    $rowNumber,
                    $reference->sourceColumn,
                    $reference->errorCode,
                    $reference->errorMessage ?? "Reference [{$value}] was not found.",
                    $row,
                );
            }
        }

        return null;
    }

    /**
     * @param  list<array{row_number:int,source:array<string,mixed>,payload:array<string,mixed>,key_hash:string}>  $rows
     * @return array{list<array<string,mixed>>,list<RowFailure>,int,null|array{connection:string,scope:string,hashes:list<string>}}
     */
    private function filterDatabaseDuplicates(
        Import $import,
        ImportDefinition $definition,
        ImportContext $context,
        array $rows,
    ): array {
        $target = $definition->target();
        if ($target === null) {
            if ($definition->duplicateStrategy() !== DuplicateStrategy::UPSERT) {
                throw new ImportConfigurationException(
                    $definition::class.' must provide target() for automatic ERROR, SKIP, or UPDATE semantics.',
                );
            }

            return [$rows, [], 0, null];
        }

        $connection = $this->connections->assertMetadataAndTargetMatch($target->connectionName($context));
        $scope = $target->lockScope($context);
        $hashes = array_column($rows, 'key_hash');
        $this->keyLocks->acquire($connection, $scope, $hashes, $import->id);
        $existing = $target->existingKeyHashes(array_column($rows, 'payload'), $definition, $context);
        $writeRows = [];
        $failures = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $exists = isset($existing[$row['key_hash']]);

            if ($definition->duplicateStrategy() === DuplicateStrategy::ERROR && $exists) {
                $failures[] = new RowFailure(
                    $row['row_number'],
                    implode(',', $definition->uniqueBy()),
                    'duplicate_in_database',
                    'The business key already exists in the target database.',
                    $row['source'],
                );

                continue;
            }

            if ($definition->duplicateStrategy() === DuplicateStrategy::SKIP && $exists) {
                $skipped++;

                continue;
            }

            if ($definition->duplicateStrategy() === DuplicateStrategy::UPDATE && ! $exists) {
                $skipped++;

                continue;
            }

            $row['existed'] = $exists;
            $writeRows[] = $row;
        }

        return [$writeRows, $failures, $skipped, compact('connection', 'scope', 'hashes')];
    }

    /** @param list<array{row_number:int,source:array<string,mixed>,payload:array<string,mixed>,key_hash:string}> $rows */
    private function stageRows(Import $import, ImportChunk $chunk, array $rows): void
    {
        $now = now();
        $records = array_map(static fn (array $row): array => [
            'id' => (string) Str::ulid(),
            'import_id' => $import->id,
            'import_chunk_id' => $chunk->id,
            'row_number' => $row['row_number'],
            'business_key_hash' => $row['key_hash'],
            'source_data' => json_encode($row['source'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'payload' => json_encode($row['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
        if ($records === []) {
            return;
        }

        $connection = $this->database->connection(config('bulk-imports.database.connection'));
        $budget = match ($connection->getDriverName()) {
            'sqlite' => 900,
            'sqlsrv' => 2000,
            default => 60000,
        };
        $batchSize = max(1, min(
            (int) config('bulk-imports.atomic.staging_batch_size', 1000),
            intdiv($budget, count($records[0])),
        ));

        foreach (array_chunk($records, $batchSize) as $batch) {
            ImportStagedRow::query()->upsert(
                $batch,
                ['import_id', 'row_number'],
                ['import_chunk_id', 'business_key_hash', 'source_data', 'payload', 'updated_at'],
            );
        }
    }

    private function assertNotCancelled(Import $import): void
    {
        if ($import->status === ImportStatus::CANCELLED || $import->cancel_requested_at !== null) {
            throw new ImportCancelledSignal('Import cancellation was requested.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertCompleteBusinessKey(array $payload, ImportDefinition $definition): void
    {
        foreach ($definition->targetUniqueBy() as $column) {
            if (! array_key_exists($column, $payload)
                || $payload[$column] === null
                || trim((string) $payload[$column]) === '') {
                throw new ImportConfigurationException(
                    $definition::class."::transform() must retain a non-empty [{$column}] business-key value.",
                );
            }
        }
    }

    public function markCancelled(string $chunkId, string $leaseToken): void
    {
        ImportChunk::query()
            ->whereKey($chunkId)
            ->where('lease_token', $leaseToken)
            ->where('status', ChunkStatus::PROCESSING->value)
            ->update([
                'status' => ChunkStatus::CANCELLED->value,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
