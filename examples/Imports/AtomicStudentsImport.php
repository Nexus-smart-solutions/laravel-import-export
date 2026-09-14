<?php

namespace Nexus\ImportExport\Examples\Imports;

use Nexus\ImportExport\Contracts\AtomicCommitter;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Services\EloquentAtomicCommitter;

final class AtomicStudentsImport extends StudentsImport
{
    public function mode(): ImportMode
    {
        return ImportMode::ATOMIC;
    }

    public function atomicCommitter(): AtomicCommitter
    {
        return app(EloquentAtomicCommitter::class);
    }
}
