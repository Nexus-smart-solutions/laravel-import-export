<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Nexus\ImportExport\Jobs\FinalizeImportJob;
use Nexus\ImportExport\Jobs\ProcessImportChunkJob;
use Nexus\ImportExport\Models\Import;

final class ChunkBatchDispatcher
{
    /** @param list<string> $chunkIds */
    public function dispatch(Import $import, array $chunkIds): void
    {
        $importId = $import->id;
        $context = $import->context ?? [];
        $queue = (string) config('bulk-imports.queue.name', 'imports');
        $connection = config('bulk-imports.queue.connection');

        if ($chunkIds === []) {
            $finalizer = FinalizeImportJob::dispatch($importId, $context);
            if ($connection !== null) {
                $finalizer->onConnection($connection);
            }
            $finalizer->onQueue($queue);
            unset($finalizer);
            Import::query()->whereKey($importId)->update([
                'batch_dispatched_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $jobs = array_map(
            static fn (string $chunkId): ProcessImportChunkJob => new ProcessImportChunkJob($chunkId, $context),
            $chunkIds,
        );
        $pending = Bus::batch($jobs)
            ->name("bulk-import:{$importId}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($importId, $context, $connection, $queue): void {
                $finalizer = FinalizeImportJob::dispatch($importId, $context)->afterCommit();
                if ($connection !== null) {
                    $finalizer->onConnection($connection);
                }
                $finalizer->onQueue($queue);
                unset($finalizer);
            });

        if ($connection !== null) {
            $pending->onConnection($connection);
        }
        $pending->onQueue($queue);

        $batch = $pending->dispatch();
        Import::query()->whereKey($importId)->update([
            'queue_batch_id' => $batch->id,
            'batch_dispatched_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
