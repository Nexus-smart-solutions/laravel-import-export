<?php

namespace Nexus\ImportExport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Events\ExportChanged;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Exports\ExportRunner;
use Nexus\ImportExport\Models\Export;
use Throwable;

final class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public string $exportId, public array $context, public int $revision)
    {
        $this->tries = (int) config('import-export.exports.tries', 3);
        $this->timeout = (int) config('import-export.exports.timeout', 3600);
    }

    public function backoff(): array
    {
        return config('import-export.exports.backoff', [10, 30, 90]);
    }

    public function handle(ContextRestorer $restorer, ExportRunner $runner): void
    {
        $restorer->run($this->context, function () use ($runner): void {
            if ($runner->step($this->exportId, $this->revision)) {
                $export = Export::query()->findOrFail($this->exportId);
                if (! $export->status->terminal()) {
                    app(ExportManager::class)->schedule($export);
                }
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(ContextRestorer::class)->run($this->context, function (): void {
            $updated = Export::query()->whereKey($this->exportId)->where('revision', $this->revision)
                ->whereNotIn('status', ['completed', 'cancelled', 'expired', 'failed'])
                ->where(function ($q): void {
                    $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
                })
                ->update(['status' => 'failed', 'failed_at' => now(), 'expires_at' => now()->addDays(config('import-export.exports.retention_days', 7)),
                    'error_message' => 'Export processing failed. Consult the worker error log using the export ID.', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            if ($updated) {
                ExportChanged::dispatch($this->exportId, 'failed', $this->context);
            }
        });
    }
}
