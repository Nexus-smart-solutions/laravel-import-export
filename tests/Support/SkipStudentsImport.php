<?php

namespace Nexus\ImportExport\Tests\Support;

use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Examples\Imports\StudentsImport;

final class SkipStudentsImport extends StudentsImport
{
    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::SKIP;
    }
}
