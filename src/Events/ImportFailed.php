<?php

namespace Nexus\ImportExport\Events;

use Illuminate\Support\Str;
use Nexus\ImportExport\Models\Import;

final class ImportFailed extends ImportEvent
{
    public string $reason;

    public function __construct(Import $import, string $reason)
    {
        parent::__construct($import);
        $this->reason = Str::limit($reason, 4000);
    }
}
