<?php

namespace Nexus\ImportExport\Enums;

enum FailureType: string
{
    case FILE_ERROR = 'file_error';
    case STRUCTURE_ERROR = 'structure_error';
    case VALIDATION_ERROR = 'validation_error';
    case REFERENCE_ERROR = 'reference_error';
    case DUPLICATE_ERROR = 'duplicate_error';
    case PERSISTENCE_ERROR = 'persistence_error';
    case SYSTEM_ERROR = 'system_error';

    public static function forCode(string $code): self
    {
        return match (true) {
            str_starts_with($code, 'reference'), str_contains($code, 'relation') => self::REFERENCE_ERROR,
            str_starts_with($code, 'duplicate') => self::DUPLICATE_ERROR,
            str_starts_with($code, 'validation'), str_starts_with($code, 'invalid') => self::VALIDATION_ERROR,
            default => self::SYSTEM_ERROR,
        };
    }
}
