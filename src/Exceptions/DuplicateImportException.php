<?php

namespace Nexus\ImportExport\Exceptions;

use Nexus\ImportExport\Models\Import;
use RuntimeException;

final class DuplicateImportException extends RuntimeException
{
    public function __construct(public readonly Import $existingImport)
    {
        parent::__construct("An import with fingerprint {$existingImport->fingerprint} already exists.");
    }
}
