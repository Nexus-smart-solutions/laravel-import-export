<?php

namespace Nexus\ImportExport\Notifications;

use Nexus\ImportExport\Events\ExportChanged;
use Nexus\ImportExport\Events\ImportEvent;

final class QueueCompletionNotice
{
    public function handle(ImportEvent|ExportChanged $event): void
    {
        $status = $event instanceof ImportEvent ? $event->status : $event->phase;
        if (! in_array($status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return;
        }
        app(NotificationDispatcher::class)->queue($event instanceof ImportEvent ? 'import' : 'export',
            $event instanceof ImportEvent ? $event->importId : $event->exportId, $status, $event->context);
    }
}
