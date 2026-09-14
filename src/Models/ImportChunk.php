<?php

namespace Nexus\ImportExport\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Models\Concerns\UsesBulkImportConnection;

/**
 * @property string $id
 * @property string $import_id
 * @property int $chunk_number
 * @property int $start_row
 * @property int $end_row
 * @property string $disk
 * @property string $file_path
 * @property string $checksum
 * @property ChunkStatus $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $succeeded_rows
 * @property int $failed_rows
 * @property int $skipped_rows
 * @property int $staged_rows
 * @property int $attempts
 * @property string|null $lease_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $inserted_rows
 * @property int $updated_rows
 * @property int $would_insert
 * @property int $would_update
 * @property Import $import
 */
class ImportChunk extends Model
{
    use HasUlids;
    use UsesBulkImportConnection;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('bulk-imports.database.tables.chunks', 'import_chunks');
    }

    protected function casts(): array
    {
        return [
            'status' => ChunkStatus::class,
            'chunk_number' => 'integer',
            'start_row' => 'integer',
            'end_row' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'inserted_rows' => 'integer', 'updated_rows' => 'integer', 'would_insert' => 'integer', 'would_update' => 'integer',
            'succeeded_rows' => 'integer',
            'failed_rows' => 'integer',
            'skipped_rows' => 'integer',
            'staged_rows' => 'integer',
            'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(ImportFailure::class);
    }

    public function stagedRows(): HasMany
    {
        return $this->hasMany(ImportStagedRow::class);
    }
}
