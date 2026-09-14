<?php

namespace Nexus\ImportExport\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonSerializable;
use Nexus\ImportExport\Data\SourceRow;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Events\ImportStarted;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Exceptions\ImportCancelledSignal;
use Nexus\ImportExport\Exceptions\RejectRow;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Models\ImportFailure;
use Nexus\ImportExport\Models\ImportRowKey;
use Nexus\ImportExport\Models\ImportStagedRow;
use Nexus\ImportExport\Readers\ReaderRegistry;
use Nexus\ImportExport\State\ImportStateMachine;
use Nexus\ImportExport\Support\Header;
use Stringable;

final class PrepareImport
{
    public function __construct(
        private readonly ImportDefinitionRegistry $definitions,
        private readonly ReaderRegistry $readers,
        private readonly ChunkFileStore $chunks,
        private readonly ImportStateMachine $states,
        private readonly ImportLeaseManager $leases,
        private readonly ChunkBatchDispatcher $batchDispatcher,
        private readonly StoredFileIntegrityVerifier $integrity,
    ) {}

    public function handle(Import $import, string $leaseToken): void
    {
        $this->integrity->verify($import->disk, $import->file_path, $import->file_hash);
        $definition = $this->definitions->make($import->type);
        if ($definition instanceof DataDefinition) {
            $definition->inContext($import->context ?? []);
        }
        if ($definition->version() !== $import->definition_version) {
            throw new \RuntimeException('The definition version changed; submit a new import.');
        }
        $aliases = $this->mapping($definition->headerAliases(), $definition->columns(), $import->options['mapping'] ?? []);
        $extension = strtolower(pathinfo($import->file_path, PATHINFO_EXTENSION));
        $reader = $this->readers->for($extension);
        $inspection = $reader->inspect($import->disk, $import->file_path, $definition->worksheet());
        $header = $this->canonicalHeader($inspection->headers, $aliases);
        $this->validateHeader($header, $definition->requiredColumns());
        $this->assertPreparationLease($import, $leaseToken);
        $this->cleanPreviousPreparation($import);

        $chunkSize = max(1, (int) ($import->options['chunk_size'] ?? config('bulk-imports.chunk_size', 1000)));
        $maxRows = (int) config('bulk-imports.limits.max_rows', 1000000);
        $buffer = [];
        $rowCount = 0;
        $bufferBytes = 0;
        $chunkNumber = 0;

        foreach ($reader->rows($import->disk, $import->file_path, $definition->worksheet()) as $sourceRow) {
            $rowCount++;
            if ($rowCount > $maxRows) {
                throw new FileValidationException(['file' => ["The source exceeds the configured {$maxRows} row limit."]]);
            }

            $data = $this->canonicalRow($sourceRow, $aliases, $definition->columns());
            $this->assertCellLimits($data, $sourceRow->rowNumber);
            $raw = $data;
            $normalizationError = null;
            try {
                $data = $definition->normalize($data);
            } catch (RejectRow $error) {
                $normalizationError = ['column' => $error->column, 'code' => $error->errorCode, 'message' => $error->getMessage()];
            }
            $keyHash = $this->hasCompleteBusinessKey($data, $definition->uniqueBy())
                ? $definition->sourceBusinessKeyHash($data)
                : null;

            $buffer[] = [
                'row_number' => $sourceRow->rowNumber,
                'data' => $data,
                'raw' => $raw,
                'sheet' => $sourceRow->sheet,
                'sheet_row' => $sourceRow->sheetRow ?? $sourceRow->rowNumber,
                'normalization_error' => $normalizationError,
                'business_key_hash' => $keyHash,
                'duplicate_of_row' => null,
            ];

            $bufferBytes += strlen(json_encode(end($buffer), JSON_THROW_ON_ERROR));
            if (count($buffer) >= $chunkSize || $bufferBytes >= (int) config('import-export.chunk_max_bytes', 16777216)) {
                $chunkNumber++;
                $this->persistChunk($import, $chunkNumber, $buffer, $leaseToken);
                $buffer = [];
                $bufferBytes = 0;
                $this->assertPreparationLease($import, $leaseToken);
            }
        }

        if ($buffer !== []) {
            $chunkNumber++;
            $this->persistChunk($import, $chunkNumber, $buffer, $leaseToken);
        }
        $this->assertPreparationLease($import, $leaseToken);

        if ($rowCount === 0) {
            throw new FileValidationException(['file' => ['The source contains no data rows.']]);
        }
        $updated = Import::query()->whereKey($import->id)->where('lease_token', $leaseToken)->update(['total_rows' => $rowCount, 'total_chunks' => $chunkNumber]);
        if ($updated !== 1) {
            throw new \RuntimeException('Preparation lease lost.');
        }
        $import = $this->states->transition($import->refresh(), ImportStatus::PROCESSING, [
            'error_message' => null,
            'failure_stage' => null,
        ]);
        ImportStarted::dispatch($import);

        $this->dispatchPreparedBatch($import, $leaseToken);
    }

    private function mapping(array $aliases, array $columns, array $manual): array
    {
        $result = [];
        foreach (array_merge(array_combine($columns, $columns), $aliases) as $header => $column) {
            $key = Header::normalize($header);
            if (isset($result[$key]) && $result[$key] !== $column) {
                throw new FileValidationException(['headers' => ['Ambiguous header aliases.']]);
            }
            $result[$key] = $column;
        }
        foreach ($manual as $header => $column) {
            if (! is_string($header) || ! is_string($column) || ! in_array($column, $columns, true)) {
                throw new FileValidationException(['mapping' => ['Only importable definition fields can be mapped.']]);
            }
            $result[Header::normalize($header)] = $column;
        }

        return $result;
    }

    /** @param list<string> $headers @param array<string,string> $aliases @return list<string> */
    private function canonicalHeader(array $headers, array $aliases): array
    {
        return array_map(static fn (string $header): string => $aliases[Header::normalize($header)] ?? Header::normalize($header), $headers);
    }

    /** @param list<string> $headers @param list<string> $required */
    private function validateHeader(array $headers, array $required): void
    {
        $errors = [];
        $duplicates = array_keys(array_filter(array_count_values($headers), static fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff($required, $headers));

        if (count($headers) > (int) config('bulk-imports.limits.max_columns', 250)) {
            $errors['headers'][] = 'The source contains too many columns.';
        }
        if ($duplicates !== []) {
            $errors['headers'][] = 'Duplicate headers: '.implode(', ', $duplicates).'.';
        }
        if ($missing !== []) {
            $errors['headers'][] = 'Missing mandatory headers: '.implode(', ', $missing).'.';
        }
        if ($errors !== []) {
            throw new FileValidationException($errors);
        }
    }

    /** @param array<string,string> $aliases @param list<string> $columns @return array<string,mixed> */
    private function canonicalRow(SourceRow $row, array $aliases, array $columns): array
    {
        $canonical = [];

        foreach ($row->data as $header => $value) {
            $column = $aliases[Header::normalize($header)] ?? Header::normalize($header);
            if (in_array($column, $columns, true)) {
                $canonical[$column] = $this->normalizeCell($value);
            }
        }

        return $canonical;
    }

    private function normalizeCell(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value,
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Stringable => (string) $value,
            is_scalar($value), $value === null => $value,
            default => throw new FileValidationException(['file' => ['A cell contains an unsupported value type.']]),
        };
    }

    /** @param array<string,mixed> $row */
    private function assertCellLimits(array $row, int $rowNumber): void
    {
        $max = (int) config('bulk-imports.limits.max_cell_length', 65535);
        foreach ($row as $column => $value) {
            if (is_string($value) && strlen($value) > $max) {
                throw new FileValidationException([
                    'file' => ["Cell [{$column}] at row {$rowNumber} exceeds the configured length limit."],
                ]);
            }
        }
    }

    /** @param array<string,mixed> $row @param list<string> $uniqueBy */
    private function hasCompleteBusinessKey(array $row, array $uniqueBy): bool
    {
        foreach ($uniqueBy as $column) {
            if (! array_key_exists($column, $row) || $row[$column] === null || trim((string) $row[$column]) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{row_number:int,data:array<string,mixed>,business_key_hash:?string,duplicate_of_row:?int}> $rows */
    private function persistChunk(Import $import, int $chunkNumber, array $rows, string $leaseToken): void
    {
        DB::connection(config('bulk-imports.database.connection'))->transaction(function () use ($import, $chunkNumber, &$rows, $leaseToken): void {
            $current = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($current->lease_token !== $leaseToken || $current->status !== ImportStatus::VALIDATING) {
                throw new \RuntimeException('Preparation lease lost before writing a chunk.');
            }
            $claims = [];
            foreach ($rows as $row) {
                $hash = $row['business_key_hash'];
                if ($hash !== null && ! isset($claims[$hash])) {
                    $claims[$hash] = $row['row_number'];
                }
            }

            $now = now();
            $claimRows = [];
            foreach ($claims as $hash => $rowNumber) {
                $claimRows[] = [
                    'id' => (string) Str::ulid(),
                    'import_id' => $import->id,
                    'first_chunk_id' => null,
                    'key_hash' => $hash,
                    'first_row_number' => $rowNumber,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($claimRows !== []) {
                // Keep well below SQLite's and SQL Server's parameter ceilings.
                foreach (array_chunk($claimRows, 100) as $claimBatch) {
                    ImportRowKey::query()->insertOrIgnore($claimBatch);
                }
            }

            $firstRows = [];
            foreach (array_chunk(array_keys($claims), 500) as $hashBatch) {
                $firstRows += ImportRowKey::query()
                    ->where('import_id', $import->id)
                    ->whereIn('key_hash', $hashBatch)
                    ->pluck('first_row_number', 'key_hash')
                    ->all();
            }

            foreach ($rows as &$row) {
                $hash = $row['business_key_hash'];
                if ($hash !== null && isset($firstRows[$hash]) && (int) $firstRows[$hash] !== $row['row_number']) {
                    $row['duplicate_of_row'] = (int) $firstRows[$hash];
                }
            }

            $stored = $this->chunks->write($import->disk, $import->id, $chunkNumber, $rows);
            $chunk = ImportChunk::query()->create([
                'import_id' => $import->id,
                'chunk_number' => $chunkNumber,
                'start_row' => $rows[0]['row_number'],
                'end_row' => $rows[array_key_last($rows)]['row_number'],
                'disk' => $import->disk,
                'file_path' => $stored['path'],
                'checksum' => $stored['checksum'],
                'status' => ChunkStatus::QUEUED,
                'total_rows' => count($rows),
            ]);

            foreach (array_chunk(array_keys($claims), 500) as $hashBatch) {
                ImportRowKey::query()
                    ->where('import_id', $import->id)
                    ->whereNull('first_chunk_id')
                    ->whereIn('key_hash', $hashBatch)
                    ->update(['first_chunk_id' => $chunk->id, 'updated_at' => now()]);
            }
        });
    }

    private function cleanPreviousPreparation(Import $import): void
    {
        $this->chunks->deleteImportDirectory($import->disk, $import->id);
        DB::connection(config('bulk-imports.database.connection'))->transaction(function () use ($import): void {
            ImportFailure::query()->where('import_id', $import->id)->delete();
            ImportStagedRow::query()->where('import_id', $import->id)->delete();
            ImportRowKey::query()->where('import_id', $import->id)->delete();
            ImportChunk::query()->where('import_id', $import->id)->delete();
            Import::query()->whereKey($import->id)->update([
                'total_rows' => 0,
                'processed_rows' => 0,
                'succeeded_rows' => 0,
                'failed_rows' => 0,
                'skipped_rows' => 0,
                'staged_rows' => 0,
                'total_chunks' => 0,
                'inserted_rows' => 0, 'updated_rows' => 0, 'would_insert' => 0, 'would_update' => 0, 'processed_chunks' => 0,
                'queue_batch_id' => null,
                'batch_dispatched_at' => null,
                'updated_at' => now(),
            ]);
        });
    }

    public function dispatchPreparedBatch(Import $import, string $leaseToken): void
    {
        $current = Import::query()
            ->whereKey($import->id)
            ->where('status', ImportStatus::PROCESSING->value)
            ->where('lease_token', $leaseToken)
            ->first();
        if ($current === null) {
            if (Import::query()->whereKey($import->id)->value('status') === ImportStatus::CANCELLED->value) {
                throw new ImportCancelledSignal('Import cancellation was requested before batch dispatch.');
            }

            throw new \RuntimeException('The preparation lease was lost before batch dispatch.');
        }

        $chunkIds = ImportChunk::query()
            ->where('import_id', $import->id)
            ->where('status', ChunkStatus::QUEUED->value)
            ->orderBy('chunk_number')
            ->pluck('id')
            ->all();
        $this->batchDispatcher->dispatch($current, $chunkIds);
        $this->leases->release($import->id, $leaseToken);
    }

    public function discardCancelledPreparation(string $importId): void
    {
        $import = Import::query()->find($importId);
        if ($import !== null && $import->status === ImportStatus::CANCELLED) {
            $this->cleanPreviousPreparation($import);
        }
    }

    private function assertPreparationLease(Import $import, string $leaseToken): void
    {
        if ($this->leases->heartbeat($import->id, $leaseToken)) {
            return;
        }

        $current = Import::query()->find($import->id);
        if ($current?->status === ImportStatus::CANCELLED) {
            throw new ImportCancelledSignal('Import cancellation was requested during preparation.');
        }

        throw new \RuntimeException('The preparation lease was lost before chunk dispatch.');
    }
}
