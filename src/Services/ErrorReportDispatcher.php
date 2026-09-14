<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Jobs\GenerateImportErrorReportJob;
use Nexus\ImportExport\Models\Import;

final class ErrorReportDispatcher
{
    public function dispatch(Import $import): void
    {
        if ($import->failed_rows <= 0 || $import->error_report_path !== null) {
            return;
        }

        $pending = GenerateImportErrorReportJob::dispatch($import->id, $import->context ?? []);
        if (($connection = config('bulk-imports.queue.connection')) !== null) {
            $pending->onConnection($connection);
        }
        $pending->onQueue((string) config('bulk-imports.queue.name', 'imports'));
        unset($pending);
    }
}
