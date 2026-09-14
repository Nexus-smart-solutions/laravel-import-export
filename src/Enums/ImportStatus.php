<?php

namespace Nexus\ImportExport\Enums;

enum ImportStatus: string
{
    case UPLOADED = 'uploaded';
    case QUEUED = 'queued';
    case VALIDATING = 'validating';
    case PROCESSING = 'processing';
    case FINALIZING = 'finalizing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::COMPLETED_WITH_ERRORS,
            self::FAILED,
            self::CANCELLED,
        ], true);
    }
}
