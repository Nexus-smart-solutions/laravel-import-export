<?php

namespace Nexus\ImportExport\Exceptions;

use RuntimeException;

final class FileValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(collect($errors)->flatten()->implode(' '));
    }
}
