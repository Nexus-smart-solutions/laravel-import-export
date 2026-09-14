<?php

namespace Nexus\ImportExport\Enums;

enum ChunkStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
