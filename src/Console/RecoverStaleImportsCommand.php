<?php

namespace Nexus\ImportExport\Console;

use Illuminate\Console\Command;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;
use Nexus\ImportExport\Jobs\FinalizeImportJob;
use Nexus\ImportExport\Jobs\PrepareImportJob;
use Nexus\ImportExport\Jobs\ProcessImportChunkJob;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Services\CancelImport;
use Nexus\ImportExport\State\ImportStateMachine;

final class RecoverStaleImportsCommand extends Command
{
    protected $signature = 'bulk-imports:recover-stale';

    protected $description = 'Recover interrupted cancellations and redispatch work after hard worker failures.';

    public function handle(ImportStateMachine $states, CancelImport $cancellations): int
    {
        $queue = (string) config('bulk-imports.queue.name', 'imports');
        $connection = config('bulk-imports.queue.connection');
        $graceCutoff = now()->subSeconds((int) config('bulk-imports.queue.recovery_grace_seconds', 300));
        $count = 0;

        Import::query()
            ->whereIn('status', [
                ImportStatus::UPLOADED->value,
                ImportStatus::QUEUED->value,
                ImportStatus::VALIDATING->value,
                ImportStatus::PROCESSING->value,
                ImportStatus::FINALIZING->value,
            ])
            ->whereNotNull('cancel_requested_at')
            ->where('updated_at', '<=', $graceCutoff)
            ->eachById(function (Import $import) use ($cancellations, &$count): void {
                try {
                    $cancellations->handle($import);
                    $count++;
                } catch (InvalidStateTransition) {
                    // A concurrent finalizer/canceller won the conditional transition.
                }
            });

        Import::query()
            ->where('status', ImportStatus::UPLOADED->value)
            ->where('updated_at', '<=', $graceCutoff)
            ->eachById(function (Import $import) use ($states, $queue, $connection, &$count): void {
                if (! $states->can($import->status, ImportStatus::QUEUED)) {
                    return;
                }
                try {
                    $import = $states->transition($import, ImportStatus::QUEUED);
                } catch (InvalidStateTransition) {
                    return;
                }
                $pending = PrepareImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                Import::query()->whereKey($import->id)->update([
                    'preparation_dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            });

        Import::query()
            ->where('status', ImportStatus::QUEUED->value)
            ->whereNull('preparation_dispatched_at')
            ->where('updated_at', '<=', $graceCutoff)
            ->eachById(function (Import $import) use ($queue, $connection, &$count): void {
                $pending = PrepareImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                Import::query()->whereKey($import->id)->update([
                    'preparation_dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            });

        Import::query()
            ->where('status', ImportStatus::VALIDATING->value)
            ->where('updated_at', '<=', $graceCutoff)
            ->where(function ($query): void {
                $query->whereNull('lease_token')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->eachById(function (Import $import) use ($queue, $connection, &$count): void {
                $pending = PrepareImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                $count++;
            });

        Import::query()
            ->where('status', ImportStatus::PROCESSING->value)
            ->whereNull('batch_dispatched_at')
            ->where('updated_at', '<=', $graceCutoff)
            ->where(function ($query): void {
                $query->whereNull('lease_token')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->eachById(function (Import $import) use ($queue, $connection, &$count): void {
                $pending = PrepareImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                $count++;
            });

        ImportChunk::query()
            ->where('status', ChunkStatus::PROCESSING->value)
            ->where('updated_at', '<=', $graceCutoff)
            ->where(function ($query): void {
                $query->whereNull('lease_token')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->with('import:id,context,status')
            ->eachById(function (ImportChunk $chunk) use ($queue, $connection, &$count): void {
                if ($chunk->import?->status !== ImportStatus::PROCESSING) {
                    return;
                }
                $pending = ProcessImportChunkJob::dispatch($chunk->id, $chunk->import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                $count++;
            });

        Import::query()
            ->where('status', ImportStatus::FINALIZING->value)
            ->where('updated_at', '<=', $graceCutoff)
            ->where(function ($query): void {
                $query->whereNull('lease_token')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->eachById(function (Import $import) use ($queue, $connection, &$count): void {
                $pending = FinalizeImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                $count++;
            });

        // A hard failure may lose a Laravel batch callback after every chunk has
        // already reached a terminal state. Reconstruct only the finalizer; chunk
        // leases and counters remain untouched.
        Import::query()
            ->where('status', ImportStatus::PROCESSING->value)
            ->whereNotNull('batch_dispatched_at')
            ->where('updated_at', '<=', $graceCutoff)
            ->whereDoesntHave('chunks', static function ($query): void {
                $query->whereIn('status', [
                    ChunkStatus::QUEUED->value,
                    ChunkStatus::PROCESSING->value,
                ]);
            })
            ->eachById(function (Import $import) use ($queue, $connection, &$count): void {
                $pending = FinalizeImportJob::dispatch($import->id, $import->context ?? []);
                if ($connection !== null) {
                    $pending->onConnection($connection);
                }
                $pending->onQueue($queue);
                unset($pending);
                $count++;
            });

        $this->info("Recovered or redispatched {$count} stale work item(s).");

        return self::SUCCESS;
    }
}
