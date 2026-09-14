<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\Query\Expression;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Import;

final class ImportLeaseManager
{
    public function claimPreparation(string $importId, string $token): ?Import
    {
        $now = now();
        $expires = $now->copy()->addSeconds((int) config('bulk-imports.queue.prepare_lease_seconds', 660));

        $updated = Import::query()
            ->whereKey($importId)
            ->where(function ($query) use ($now): void {
                $query->where('status', ImportStatus::QUEUED->value)
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', ImportStatus::VALIDATING->value)
                            ->where(function ($lease) use ($now): void {
                                $lease->whereNull('lease_token')
                                    ->orWhereNull('lease_expires_at')
                                    ->orWhere('lease_expires_at', '<=', $now);
                            });
                    });
            })
            ->update([
                'status' => ImportStatus::VALIDATING->value,
                'lease_token' => $token,
                'lease_expires_at' => $expires,
                'attempts' => new Expression('attempts + 1'),
                'started_at' => new Expression('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'updated_at' => $now,
            ]);

        return $updated === 1 ? Import::query()->findOrFail($importId) : null;
    }

    public function heartbeat(string $importId, string $token): bool
    {
        return Import::query()
            ->whereKey($importId)
            ->where('lease_token', $token)
            ->update([
                'lease_expires_at' => now()->addSeconds((int) config('bulk-imports.queue.prepare_lease_seconds', 660)),
                'updated_at' => now(),
            ]) === 1;
    }

    public function release(string $importId, string $token): void
    {
        Import::query()
            ->whereKey($importId)
            ->where('lease_token', $token)
            ->update(['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    /**
     * Resume only the small dispatch phase after preparation already committed its
     * immutable chunk records but a crash happened before the dispatch marker was saved.
     */
    public function claimBatchDispatch(string $importId, string $token): ?Import
    {
        $now = now();
        $expires = $now->copy()->addSeconds((int) config('bulk-imports.queue.prepare_lease_seconds', 660));

        $updated = Import::query()
            ->whereKey($importId)
            ->where('status', ImportStatus::PROCESSING->value)
            ->whereNull('batch_dispatched_at')
            ->where(function ($lease) use ($now): void {
                $lease->whereNull('lease_token')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $now);
            })
            ->update([
                'lease_token' => $token,
                'lease_expires_at' => $expires,
                'updated_at' => $now,
            ]);

        return $updated === 1 ? Import::query()->findOrFail($importId) : null;
    }

    public function claimFinalization(string $importId, string $token): ?Import
    {
        $now = now();

        $updated = Import::query()
            ->whereKey($importId)
            ->whereNull('cancel_requested_at')
            ->whereDoesntHave('chunks', static function ($query): void {
                $query->whereIn('status', [
                    ChunkStatus::QUEUED->value,
                    ChunkStatus::PROCESSING->value,
                ]);
            })
            ->where(function ($query) use ($now): void {
                $query->where('status', ImportStatus::PROCESSING->value)
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', ImportStatus::FINALIZING->value)
                            ->where(function ($lease) use ($now): void {
                                $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                            });
                    });
            })
            ->update([
                'status' => ImportStatus::FINALIZING->value,
                'lease_token' => $token,
                'lease_expires_at' => $now->copy()->addSeconds((int) config('bulk-imports.queue.finalizer_lease_seconds', 960)),
                'updated_at' => $now,
            ]);

        return $updated === 1 ? Import::query()->findOrFail($importId) : null;
    }
}
