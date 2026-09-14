<?php

namespace Nexus\ImportExport\Data;

final readonly class BatchWriteResult
{
    public function __construct(
        public int $succeeded,
        public int $skipped = 0,
    ) {
        if ($succeeded < 0 || $skipped < 0) {
            throw new \InvalidArgumentException('Batch write counters cannot be negative.');
        }
    }

    public static function allSucceeded(int $count): self
    {
        return new self($count);
    }
}
