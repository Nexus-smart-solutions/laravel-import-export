<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Contracts\AtomicCommitter;
use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Exceptions\AtomicCommitRejected;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportStagedRow;

final class EloquentAtomicCommitter implements AtomicCommitter
{
    public function __construct(
        private readonly ConnectionGuard $connections,
        private readonly BusinessKeyLockManager $locks,
    ) {}

    public function connectionName(ImportDefinition $definition, ImportContext $context): ?string
    {
        $target = $definition->target()
            ?? throw new ImportConfigurationException('EloquentAtomicCommitter requires an ImportTarget.');

        return $target->connectionName($context);
    }

    public function commit(Import $import, ImportDefinition $definition, ImportContext $context): BatchWriteResult
    {
        $target = $definition->target()
            ?? throw new ImportConfigurationException('EloquentAtomicCommitter requires an ImportTarget.');
        $connection = $this->connections->assertMetadataAndTargetMatch($target->connectionName($context));
        $scope = $target->lockScope($context);
        $succeeded = 0;
        $skipped = 0;
        $rejections = [];
        $batchSize = max(1, (int) config('bulk-imports.atomic.staging_batch_size', 1000));
        $hashes = ImportStagedRow::query()
            ->where('import_id', $import->id)
            ->whereNotNull('business_key_hash')
            ->distinct()
            ->orderBy('business_key_hash')
            ->pluck('business_key_hash')
            ->all();
        $this->locks->acquire($connection, $scope, $hashes, $import->id);

        try {
            ImportStagedRow::query()
                ->where('import_id', $import->id)
                ->orderBy('id')
                ->chunkById($batchSize, function ($stagedRows) use (
                    $definition,
                    $context,
                    $target,
                    &$succeeded,
                    &$skipped,
                    &$rejections,
                ): void {
                    $payloads = $stagedRows->pluck('payload')->all();
                    $existing = $definition->duplicateStrategy() === DuplicateStrategy::UPSERT
                        ? []
                        : $target->existingKeyHashes($payloads, $definition, $context);
                    $writeRows = [];

                    foreach ($stagedRows as $staged) {
                        $exists = isset($existing[$staged->business_key_hash]);

                        if ($definition->duplicateStrategy() === DuplicateStrategy::ERROR && $exists) {
                            $rejections[] = [
                                'chunk_id' => $staged->import_chunk_id,
                                'row_number' => $staged->row_number,
                                'row' => $staged->source_data,
                                'column' => implode(',', $definition->uniqueBy()),
                                'code' => 'duplicate_in_database',
                                'message' => 'The business key already exists in the target database.',
                            ];

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

                        $writeRows[] = $staged->payload;
                    }

                    if ($rejections === []) {
                        $result = $target->write(
                            $writeRows,
                            $definition->targetUniqueBy(),
                            $definition->duplicateStrategy(),
                            $context,
                        );
                        $succeeded += $result->succeeded;
                        $skipped += $result->skipped;
                    }
                });
        } finally {
            $this->locks->releaseAll($connection, $scope, $import->id);
        }

        if ($rejections !== []) {
            throw new AtomicCommitRejected($rejections);
        }

        return new BatchWriteResult($succeeded, $skipped);
    }
}
