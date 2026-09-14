<?php

namespace Nexus\ImportExport;

use Nexus\ImportExport\Definitions\ImportDefinition;

final class BulkImportManager
{
    /** @param class-string<ImportDefinition> $definition */
    public function make(string $definition): PendingImport
    {
        return new PendingImport($definition);
    }
}
