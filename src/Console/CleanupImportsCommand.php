<?php

namespace Nexus\ImportExport\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemManager;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportRowKey;
use Nexus\ImportExport\Services\ChunkFileStore;

final class CleanupImportsCommand extends Command
{
    protected $signature = 'bulk-imports:cleanup {--dry-run : Show what would be removed}';

    protected $description = 'Remove retained import sources, chunks, staging, failure rows, and error reports.';

    public function handle(FilesystemManager $filesystems, ChunkFileStore $chunks): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fileCutoff = now()->subDays((int) config('bulk-imports.files.retention_days', 7));
        $reportCutoff = now()->subDays((int) config('bulk-imports.failures.retention_days', 30));
        $failureCutoff = now()->subDays((int) config('bulk-imports.failures.records_retention_days', 30));
        $staleCleanupCutoff = now()->subHour();
        $sourceCount = 0;
        $reportCount = 0;
        $failureCount = 0;

        Import::query()
            ->whereIn('status', [
                ImportStatus::COMPLETED->value,
                ImportStatus::COMPLETED_WITH_ERRORS->value,
                ImportStatus::FAILED->value,
                ImportStatus::CANCELLED->value,
            ])
            ->whereNull('files_deleted_at')
            ->where(function ($query) use ($fileCutoff): void {
                $query->where('completed_at', '<=', $fileCutoff)
                    ->orWhere('failed_at', '<=', $fileCutoff)
                    ->orWhere('cancelled_at', '<=', $fileCutoff);
            })
            ->orderBy('id')
            ->chunkById(100, function ($imports) use (
                $filesystems,
                $chunks,
                $dryRun,
                $staleCleanupCutoff,
                &$sourceCount,
            ): void {
                foreach ($imports as $import) {
                    if ($dryRun) {
                        $sourceCount++;

                        continue;
                    }

                    $claimTime = now();
                    $claimed = Import::query()
                        ->whereKey($import->id)
                        ->where('status', $import->status->value)
                        ->whereNull('files_deleted_at')
                        ->where(function ($query) use ($staleCleanupCutoff): void {
                            $query->whereNull('files_cleanup_started_at')
                                ->orWhere('files_cleanup_started_at', '<=', $staleCleanupCutoff);
                        })
                        ->update(['files_cleanup_started_at' => $claimTime, 'updated_at' => now()]);

                    if ($claimed === 1) {
                        $sourceCount++;
                        $filesystem = $filesystems->disk($import->disk);
                        $sourceDeleted = ! $filesystem->exists($import->file_path)
                            || $filesystem->delete($import->file_path);
                        $chunksDeleted = $chunks->deleteImportDirectory($import->disk, $import->id);

                        if ($sourceDeleted && $chunksDeleted) {
                            $import->stagedRows()->delete();
                            ImportRowKey::query()->where('import_id', $import->id)->delete();
                            Import::query()->whereKey($import->id)->update([
                                'files_cleanup_started_at' => null,
                                'files_deleted_at' => now(),
                                'updated_at' => now(),
                            ]);
                        } else {
                            $this->warn("Could not fully clean retained files for import {$import->id}; it will be retried.");
                        }
                    }
                }
            });

        Import::query()
            ->whereNotNull('error_report_path')
            ->where(function ($query) use ($reportCutoff): void {
                $query->where('completed_at', '<=', $reportCutoff)
                    ->orWhere('failed_at', '<=', $reportCutoff)
                    ->orWhere('cancelled_at', '<=', $reportCutoff);
            })
            ->orderBy('id')
            ->chunkById(100, function ($imports) use ($filesystems, $dryRun, &$reportCount): void {
                foreach ($imports as $import) {
                    $reportCount++;
                    if (! $dryRun) {
                        $filesystem = $filesystems->disk($import->error_report_disk);
                        $deleted = ! $filesystem->exists($import->error_report_path)
                            || $filesystem->delete($import->error_report_path);

                        if ($deleted) {
                            $import->forceFill(['error_report_disk' => null, 'error_report_path' => null])->save();
                        } else {
                            $this->warn("Could not delete the error report for import {$import->id}; it will be retried.");
                        }
                    }
                }
            });

        Import::query()
            ->whereIn('status', [
                ImportStatus::COMPLETED->value,
                ImportStatus::COMPLETED_WITH_ERRORS->value,
                ImportStatus::FAILED->value,
                ImportStatus::CANCELLED->value,
            ])
            ->whereNull('failure_records_deleted_at')
            ->where(function ($query) use ($failureCutoff): void {
                $query->where('completed_at', '<=', $failureCutoff)
                    ->orWhere('failed_at', '<=', $failureCutoff)
                    ->orWhere('cancelled_at', '<=', $failureCutoff);
            })
            ->orderBy('id')
            ->chunkById(100, function ($imports) use ($dryRun, &$failureCount): void {
                foreach ($imports as $import) {
                    $failureCount++;
                    if (! $dryRun) {
                        $import->failures()->delete();
                        $import->forceFill(['failure_records_deleted_at' => now()])->save();
                    }
                }
            });

        $verb = $dryRun ? 'Would clean' : 'Cleaned';
        $this->info(
            "{$verb} {$sourceCount} source/chunk sets, {$reportCount} reports, and {$failureCount} failure sets.",
        );

        return self::SUCCESS;
    }
}
