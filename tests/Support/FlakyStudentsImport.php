<?php

namespace Nexus\ImportExport\Tests\Support;

use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use RuntimeException;

final class FlakyStudentsImport extends StudentsImport
{
    public static bool $failNextBatch = true;

    public function handleBatch(array $rows, ImportContext $context): BatchWriteResult
    {
        if (self::$failNextBatch) {
            self::$failNextBatch = false;
            throw new RuntimeException('Simulated database disconnect.');
        }

        return parent::handleBatch($rows, $context);
    }
}
