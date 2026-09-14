<?php

namespace Nexus\ImportExport\Exceptions;

use RuntimeException;

final class RejectRow extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $column = null,
    ) {
        parent::__construct($message);
    }
}
