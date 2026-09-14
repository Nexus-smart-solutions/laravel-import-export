<?php

namespace Nexus\ImportExport\Notifications;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nexus\ImportExport\Jobs\CompletionNoticeJob;

/** This boundary must never throw into an operation's terminal transition. */
final class NotificationDispatcher
{
    public function receipts(): Builder
    {
        return DB::connection(config('bulk-imports.database.connection'))->table('data_notification_receipts');
    }

    public function queue(string $kind, string $operationId, string $status, array $context): void
    {
        if (! config('import-export.notifications.enabled', false)) {
            return;
        }
        try {
            $callback = fn () => $this->enqueue($kind, $operationId, $status, $context);
            $connection = DB::connection(config('bulk-imports.database.connection'));
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($callback);
            } else {
                $callback();
            }
        } catch (\Throwable $exception) {
            $this->logFailure($kind, $operationId, $exception);
        }
    }

    private function enqueue(string $kind, string $operationId, string $status, array $context): void
    {
        try {
            $this->record($kind, $operationId, $status, $context);
            $job = (new CompletionNoticeJob($kind, $operationId, $status, $context))->onQueue(config('import-export.notifications.queue', 'notifications'))
                ->onConnection(config('import-export.notifications.connection'));
            Bus::dispatch($job);
        } catch (\Throwable $exception) {
            $this->logFailure($kind, $operationId, $exception);
        }
    }

    public function record(string $kind, string $id, string $status, array $context): string
    {
        if (! in_array($kind, ['import', 'export'], true) || ! in_array($status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unknown notification operation/status.');
        }
        $key = hash('sha256', $kind.':'.$id.':'.$status);
        $this->receipts()->insertOrIgnore(['id' => $key, 'kind' => $kind, 'operation_id' => $id, 'status' => $status,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $key;
    }

    private function logFailure(string $kind, string $id, \Throwable $exception): void
    {
        try {
            Log::warning('Operation notification could not be delivered or queued.', ['operation' => $kind, 'operation_id' => $id, 'exception_type' => $exception::class]);
        } catch (\Throwable) { /* A broken logger must not break an already successful operation. */
        }
    }
}
