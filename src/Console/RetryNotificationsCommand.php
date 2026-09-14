<?php

namespace Nexus\ImportExport\Console;

use Illuminate\Console\Command;
use Nexus\ImportExport\Notifications\NotificationDispatcher;

final class RetryNotificationsCommand extends Command
{
    protected $signature = 'import-export:retry-notifications {--limit=100}';

    protected $description = 'Retry pending notification receipts on the current context/metadata connection';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        if (! config('import-export.notifications.enabled', false)) {
            $this->info('Notifications are disabled.');

            return self::SUCCESS;
        }
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $receipts = $dispatcher->receipts()->whereNull('sent_at')->whereNull('skipped_at')->where('updated_at', '<', now()->subSeconds(120))
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))->orderBy('id')->limit($limit)->get();
        foreach ($receipts as $receipt) {
            $dispatcher->queue($receipt->kind, $receipt->operation_id, $receipt->status, json_decode($receipt->context, true, flags: JSON_THROW_ON_ERROR));
        }
        $this->info('Considered '.$receipts->count().' notification receipts.');

        return self::SUCCESS;
    }
}
