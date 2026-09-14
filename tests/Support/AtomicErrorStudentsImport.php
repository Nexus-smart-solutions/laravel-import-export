<?php

namespace Nexus\ImportExport\Tests\Support;

use Nexus\ImportExport\Contracts\AtomicCommitter;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Services\EloquentAtomicCommitter;

final class AtomicErrorStudentsImport extends StudentsImport
{
    public function mode(): ImportMode
    {
        return ImportMode::ATOMIC;
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::ERROR;
    }

    public function atomicCommitter(): AtomicCommitter
    {
        return app(EloquentAtomicCommitter::class);
    }
}
