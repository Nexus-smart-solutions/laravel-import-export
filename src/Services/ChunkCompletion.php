<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\Query\Expression;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Exceptions\ChunkLeaseUnavailable;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;

final class ChunkCompletion
{
    public function complete(
        ImportChunk $chunk,
        string $token,
        int $succeeded,
        int $failed,
        int $skipped,
        int $staged,
        int $inserted = 0,
        int $updatedRows = 0,
        int $wouldInsert = 0,
        int $wouldUpdate = 0,
    ): ImportChunk {
        $updated = ImportChunk::query()
            ->whereKey($chunk->id)
            ->where('status', ChunkStatus::PROCESSING->value)
            ->where('lease_token', $token)
            ->update([
                'status' => ChunkStatus::COMPLETED->value,
                'processed_rows' => $chunk->total_rows,
                'succeeded_rows' => $succeeded,
                'failed_rows' => $failed,
                'skipped_rows' => $skipped,
                'staged_rows' => $staged,
                'inserted_rows' => $inserted, 'updated_rows' => $updatedRows,
                'would_insert' => $wouldInsert, 'would_update' => $wouldUpdate,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ChunkLeaseUnavailable('The chunk lease was lost before completion.');
        }

        Import::query()->whereKey($chunk->import_id)->update([
            'processed_rows' => new Expression('processed_rows + '.(int) $chunk->total_rows),
            'succeeded_rows' => new Expression('succeeded_rows + '.(int) $succeeded),
            'failed_rows' => new Expression('failed_rows + '.(int) $failed),
            'skipped_rows' => new Expression('skipped_rows + '.(int) $skipped),
            'staged_rows' => new Expression('staged_rows + '.(int) $staged),
            'inserted_rows' => new Expression('inserted_rows + '.(int) $inserted),
            'updated_rows' => new Expression('updated_rows + '.(int) $updatedRows),
            'would_insert' => new Expression('would_insert + '.(int) $wouldInsert),
            'would_update' => new Expression('would_update + '.(int) $wouldUpdate),
            'processed_chunks' => new Expression('processed_chunks + 1'),
            'updated_at' => now(),
        ]);

        return $chunk->refresh();
    }
}
