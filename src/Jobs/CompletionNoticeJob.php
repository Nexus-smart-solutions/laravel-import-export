<?php

namespace Nexus\ImportExport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Contracts\NotificationRecipientResolver;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Notifications\NotificationDispatcher;
use Nexus\ImportExport\Notifications\OperationFinished;
use Nexus\ImportExport\Support\CanonicalJson;

final class CompletionNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public string $kind, public string $operationId, public string $status, public array $context)
    {
        $this->tries = max(1, (int) config('import-export.notifications.tries', 3));
        $this->timeout = max(1, (int) config('import-export.notifications.timeout', 60));
    }

    public function backoff(): array
    {
        return config('import-export.notifications.backoff', [30, 150, 300]);
    }

    public function handle(ContextRestorer $restorer, NotificationRecipientResolver $recipients, NotificationDispatcher $dispatcher): void
    {
        if (! config('import-export.notifications.enabled', false)) {
            return;
        }
        $restorer->run($this->context, function () use ($recipients, $dispatcher): void {
            $key = $dispatcher->record($this->kind, $this->operationId, $this->status, $this->context);
            $operation = ($this->kind === 'import' ? Import::query() : Export::query())->find($this->operationId);
            if ($operation === null || $operation->status->value !== $this->status
                || ! hash_equals($operation->context_hash, hash('sha256', CanonicalJson::encode($this->context)))) {
                return;
            }
            $token = (string) Str::uuid();
            $leaseSeconds = max($this->timeout + 30, (int) config('import-export.notifications.lease_seconds', 120));
            $claimed = $dispatcher->receipts()->where('id', $key)->whereNull('sent_at')->whereNull('skipped_at')
                ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
                ->update(['lease_token' => $token, 'lease_expires_at' => now()->addSeconds($leaseSeconds), 'updated_at' => now()]);
            if (! $claimed) {
                return;
            }
            $dispatcher->receipts()->where('id', $key)->where('lease_token', $token)->increment('attempts');
            try {
                $recipient = $recipients->resolve($operation);
                if ($recipient !== null) {
                    $class = config('import-export.notifications.notification_class', OperationFinished::class);
                    if (! is_string($class) || ! is_a($class, OperationFinished::class, true)) {
                        throw new \LogicException('Notification class must extend OperationFinished.');
                    }
                    Notification::sendNow($recipient, new $class($this->kind, $this->operationId, $this->status,
                        ['total_rows' => (int) $operation->total_rows, 'processed_rows' => (int) $operation->processed_rows]));
                }
                $dispatcher->receipts()->where('id', $key)->where('lease_token', $token)->update([
                    $recipient === null ? 'skipped_at' : 'sent_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'error_code' => null, 'updated_at' => now()]);
            } catch (\Throwable $exception) {
                $dispatcher->receipts()->where('id', $key)->where('lease_token', $token)->update(['lease_token' => null, 'lease_expires_at' => null,
                    'error_code' => 'delivery_failed', 'updated_at' => now()]);
                throw $exception; // Only this notification job fails; business rows/status are untouched.
            }
        });
    }
}
