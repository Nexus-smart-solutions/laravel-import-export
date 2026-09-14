<?php

namespace Nexus\ImportExport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Services\ErrorReportGenerator;

final class GenerateImportErrorReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @param array<string, scalar|null> $context */
    public function __construct(public string $importId, public array $context)
    {
        $this->tries = (int) config('bulk-imports.queue.tries', 3);
        $this->timeout = (int) config('bulk-imports.queue.report_timeout', 120);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('bulk-imports.queue.backoff', [10, 30, 90]);
    }

    public function handle(ContextRestorer $restorer, ErrorReportGenerator $reports): void
    {
        $restorer->run($this->context, function () use ($reports): void {
            $import = Import::query()->findOrFail($this->importId);
            if ($import->failed_rows > 0) {
                $reports->generate($import);
            }
        });
    }
}
