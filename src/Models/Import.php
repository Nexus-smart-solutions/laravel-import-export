<?php

namespace Nexus\ImportExport\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\FailureStage;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Concerns\UsesBulkImportConnection;

/**
 * @property string $id
 * @property string $type
 * @property string $definition
 * @property string $definition_version
 * @property string $original_filename
 * @property string $disk
 * @property string $file_path
 * @property string $file_hash
 * @property string $fingerprint
 * @property string $deduplication_key
 * @property ImportStatus $status
 * @property ImportMode $mode
 * @property DuplicateStrategy $duplicate_strategy
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $succeeded_rows
 * @property int $failed_rows
 * @property int $skipped_rows
 * @property int $staged_rows
 * @property int $total_chunks
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property array|null $context
 * @property string $context_hash
 * @property array|null $options
 * @property CarbonImmutable|null $preparation_dispatched_at
 * @property string|null $queue_batch_id
 * @property CarbonImmutable|null $batch_dispatched_at
 * @property int $attempts
 * @property FailureStage|null $failure_stage
 * @property string|null $error_message
 * @property string|null $error_report_disk
 * @property string|null $error_report_path
 * @property string|null $lease_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $files_cleanup_started_at
 * @property CarbonImmutable|null $files_deleted_at
 * @property CarbonImmutable|null $failure_records_deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $inserted_rows
 * @property int $updated_rows
 * @property int $would_insert
 * @property int $would_update
 * @property int $processed_chunks
 * @property string|null $failure_type
 */
class Import extends Model
{
    use HasUlids;
    use UsesBulkImportConnection;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('bulk-imports.database.tables.imports', 'imports');
    }

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'mode' => ImportMode::class,
            'duplicate_strategy' => DuplicateStrategy::class,
            'failure_stage' => FailureStage::class,
            'context' => 'array',
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'inserted_rows' => 'integer', 'updated_rows' => 'integer', 'would_insert' => 'integer', 'would_update' => 'integer', 'processed_chunks' => 'integer',
            'succeeded_rows' => 'integer',
            'failed_rows' => 'integer',
            'skipped_rows' => 'integer',
            'staged_rows' => 'integer',
            'total_chunks' => 'integer',
            'attempts' => 'integer',
            'preparation_dispatched_at' => 'immutable_datetime',
            'batch_dispatched_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'files_cleanup_started_at' => 'immutable_datetime',
            'files_deleted_at' => 'immutable_datetime',
            'failure_records_deleted_at' => 'immutable_datetime',
        ];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ImportChunk::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(ImportFailure::class);
    }

    public function stagedRows(): HasMany
    {
        return $this->hasMany(ImportStagedRow::class);
    }

    public function percentage(): int
    {
        if ($this->total_rows === 0) {
            return $this->status->isTerminal() ? 100 : 0;
        }

        return min(100, (int) floor(($this->processed_rows / $this->total_rows) * 100));
    }
}
