<?php

namespace Nexus\ImportExport\Exceptions;

use RuntimeException;

final class AtomicCommitRejected extends RuntimeException
{
    /**
     * @param  list<array{chunk_id:string,row_number:int,row:array<string,mixed>,column:?string,code:string,message:string}>  $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct('The atomic merge was rejected because production-state validation failed.');
    }
}
