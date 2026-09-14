<?php

namespace Nexus\ImportExport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nexus\ImportExport\Models\Import;

abstract class ImportEvent
{
    use Dispatchable;

    public string $importId;

    public string $status;

    /** @var array<string, scalar|null> */
    public array $context;

    /** @var array<string, int> */
    public array $counters;

    public function __construct(Import $import)
    {
        $this->importId = $import->id;
        $this->status = $import->status->value;
        $this->context = $import->context ?? [];
        $this->counters = [
            'total' => (int) $import->total_rows,
            'processed' => (int) $import->processed_rows,
            'succeeded' => (int) $import->succeeded_rows,
            'failed' => (int) $import->failed_rows,
            'skipped' => (int) $import->skipped_rows,
            'staged' => (int) $import->staged_rows,
        ];
    }
}
