<?php

namespace Nexus\ImportExport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Enums\FailureStage;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Events\ImportFailed;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Exceptions\ImportCancelledSignal;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Services\ImportLeaseManager;
use Nexus\ImportExport\Services\PrepareImport;
use Nexus\ImportExport\State\ImportStateMachine;
use Throwable;

final class PrepareImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @param array<string, scalar|null> $context */
    public function __construct(public string $importId, public array $context)
    {
        $this->tries = (int) config('bulk-imports.queue.tries', 3);
        $this->timeout = (int) config('bulk-imports.queue.prepare_timeout', 600);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('bulk-imports.queue.backoff', [10, 30, 90]);
    }

    public function handle(ContextRestorer $restorer, ImportLeaseManager $leases, PrepareImport $prepare): void
    {
        $restorer->run($this->context, function () use ($leases, $prepare): void {
            $token = (string) Str::uuid();
            $import = $leases->claimPreparation($this->importId, $token);
            if ($import === null) {
                $import = $leases->claimBatchDispatch($this->importId, $token);
                if ($import === null) {
                    return;
                }

                try {
                    $prepare->dispatchPreparedBatch($import, $token);
                } catch (Throwable $exception) {
                    $leases->release($this->importId, $token);
                    throw $exception;
                }

                return;
            }

            try {
                $prepare->handle($import, $token);
            } catch (ImportCancelledSignal) {
                $prepare->discardCancelledPreparation($this->importId);
                $leases->release($this->importId, $token);
            } catch (Throwable $exception) {
                $leases->release($this->importId, $token);
                $current = Import::query()->find($this->importId);
                if ($current?->status === ImportStatus::CANCELLED) {
                    $prepare->discardCancelledPreparation($this->importId);

                    return;
                }
                throw $exception;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(ContextRestorer::class)->run($this->context, function () use ($exception): void {
            $import = Import::query()->find($this->importId);
            if ($import === null || $import->status->isTerminal()) {
                return;
            }

            $reason = Str::limit($exception?->getMessage() ?? 'Preparation failed.', 4000);
            $import = app(ImportStateMachine::class)->transition($import, ImportStatus::FAILED, [
                'failure_stage' => FailureStage::PREPARATION->value,
                'failure_type' => $exception instanceof FileValidationException ? (isset($exception->errors['headers']) ? 'structure_error' : 'file_error') : 'system_error',
                'error_message' => $reason,
                'failed_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);
            ImportFailed::dispatch($import, $reason);
        });
    }
}
