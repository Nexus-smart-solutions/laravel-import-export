<?php

namespace Nexus\ImportExport\Enums;

enum ExportStatus: string
{
    case QUEUED = 'queued';
    case PREPARING = 'preparing';
    case PROCESSING = 'processing';
    case FINALIZING = 'finalizing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLING = 'cancelling';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function terminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }
}
