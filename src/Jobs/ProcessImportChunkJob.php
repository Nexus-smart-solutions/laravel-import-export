<?php

namespace Nexus\ImportExport\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Events\ImportChunkFailed;
use Nexus\ImportExport\Events\ImportChunkStarted;
use Nexus\ImportExport\Exceptions\ImportCancelledSignal;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Services\ChunkLeaseManager;
use Nexus\ImportExport\Services\ChunkProcessor;
use Throwable;

final class ProcessImportChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @param array<string, scalar|null> $context */
    public function __construct(public string $chunkId, public array $context)
    {
        $this->tries = (int) config('bulk-imports.queue.tries', 3);
        $this->timeout = (int) config('bulk-imports.queue.chunk_timeout', 120);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('bulk-imports.queue.backoff', [10, 30, 90]);
    }

    public function handle(
        ContextRestorer $restorer,
        ChunkLeaseManager $leases,
        ChunkProcessor $processor,
    ): void {
        $restorer->run($this->context, function () use ($leases, $processor): void {
            $token = (string) Str::uuid();
            $chunk = $leases->claim($this->chunkId, $token);
            if ($chunk === null) {
                return;
            }

            try {
                ImportChunkStarted::dispatch($chunk, $this->context);
                $processor->handle($chunk, $token);
            } catch (ImportCancelledSignal) {
                $processor->markCancelled($chunk->id, $token);
            } catch (Throwable $exception) {
                $leases->markRetryableFailure($chunk->id, $token, Str::limit($exception->getMessage(), 4000));
                throw $exception;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(ContextRestorer::class)->run($this->context, function () use ($exception): void {
            $chunk = ImportChunk::query()->find($this->chunkId);
            if ($chunk !== null && $chunk->status !== ChunkStatus::COMPLETED) {
                ImportChunkFailed::dispatch($chunk, $exception?->getMessage() ?? 'Chunk failed.', $this->context);
            }
        });
    }
}
