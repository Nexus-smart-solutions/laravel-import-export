<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\Query\Expression;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Models\ImportChunk;

final class ChunkLeaseManager
{
    public function claim(string $chunkId, string $token): ?ImportChunk
    {
        $now = now();
        $expires = $now->copy()->addSeconds((int) config('bulk-imports.queue.chunk_lease_seconds', 180));

        $updated = ImportChunk::query()
            ->whereKey($chunkId)
            ->where(function ($query) use ($now): void {
                $query->whereIn('status', [ChunkStatus::QUEUED->value, ChunkStatus::FAILED->value])
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', ChunkStatus::PROCESSING->value)
                            ->where(function ($lease) use ($now): void {
                                $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                            });
                    });
            })
            ->update([
                'status' => ChunkStatus::PROCESSING->value,
                'lease_token' => $token,
                'lease_expires_at' => $expires,
                'last_heartbeat_at' => $now,
                'attempts' => new Expression('attempts + 1'),
                'started_at' => new Expression('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'failed_at' => null,
                'error_message' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1 ? ImportChunk::query()->findOrFail($chunkId) : null;
    }

    public function heartbeat(string $chunkId, string $token): bool
    {
        $now = now();

        return ImportChunk::query()
            ->whereKey($chunkId)
            ->where('status', ChunkStatus::PROCESSING->value)
            ->where('lease_token', $token)
            ->update([
                'last_heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds((int) config('bulk-imports.queue.chunk_lease_seconds', 180)),
                'updated_at' => $now,
            ]) === 1;
    }

    public function markRetryableFailure(string $chunkId, string $token, string $message): void
    {
        ImportChunk::query()
            ->whereKey($chunkId)
            ->where('status', ChunkStatus::PROCESSING->value)
            ->where('lease_token', $token)
            ->update([
                'status' => ChunkStatus::FAILED->value,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failed_at' => now(),
                'error_message' => $message,
                'updated_at' => now(),
            ]);
    }
}
