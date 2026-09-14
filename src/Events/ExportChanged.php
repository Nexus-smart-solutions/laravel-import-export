<?php

namespace Nexus\ImportExport\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class ExportChanged
{
    use Dispatchable;

    public function __construct(public string $exportId, public string $phase, public array $context = []) {}
}
