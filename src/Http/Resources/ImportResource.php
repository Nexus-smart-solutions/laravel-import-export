<?php

namespace Nexus\ImportExport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nexus\ImportExport\Models\Import;

/** @mixin Import */
final class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'originalFilename' => $this->original_filename,
            'status' => $this->status->value,
            'mode' => $this->mode->value,
            'duplicateStrategy' => $this->duplicate_strategy->value,
            'progress' => [
                'totalRows' => $this->total_rows,
                'processedRows' => $this->processed_rows,
                'succeededRows' => $this->succeeded_rows,
                'failedRows' => $this->failed_rows,
                'skippedRows' => $this->skipped_rows,
                'stagedRows' => $this->staged_rows,
                'insertedRows' => $this->inserted_rows, 'updatedRows' => $this->updated_rows,
                'wouldInsert' => $this->would_insert, 'wouldUpdate' => $this->would_update,
                'processedChunks' => $this->processed_chunks,
                'percentage' => $this->percentage(),
            ],
            'chunks' => [
                'total' => $this->total_chunks,
                'completed' => (int) ($this->chunks_completed_count ?? 0),
                'processing' => (int) ($this->chunks_processing_count ?? 0),
                'queued' => (int) ($this->chunks_queued_count ?? 0),
                'failed' => (int) ($this->chunks_failed_count ?? 0),
                'cancelled' => (int) ($this->chunks_cancelled_count ?? 0),
            ],
            'error' => $this->when($this->error_message !== null, [
                'stage' => $this->failure_stage?->value,
                'type' => $this->failure_type,
                'message' => $this->error_message,
            ]),
            'errorReportAvailable' => $this->error_report_path !== null,
            'startedAt' => $this->started_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'failedAt' => $this->failed_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
