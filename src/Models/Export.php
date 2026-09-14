<?php

namespace Nexus\ImportExport\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Nexus\ImportExport\Enums\ExportStatus;
use Nexus\ImportExport\Models\Concerns\UsesBulkImportConnection;

/**
 * @property string $id
 * @property string $resource
 * @property string $definition
 * @property string $definition_version
 * @property string $format
 * @property string $disk
 * @property string $filename
 * @property ExportStatus $status
 * @property string $actor_type
 * @property string $actor_id
 * @property string $context_hash
 * @property string|null $file_path
 * @property string|null $high_water
 * @property string|null $lease_token
 * @property string|null $error_message
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $parts_count
 * @property int $revision
 * @property int $attempts
 * @property array $context
 * @property array $request
 * @property array $headers
 * @property array|null $checkpoint
 * @property bool $read_complete
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $prepared_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Export extends Model
{
    use HasUlids, UsesBulkImportConnection;

    protected $table = 'data_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class, 'context' => 'array', 'request' => 'array', 'headers' => 'array', 'checkpoint' => 'array',
            'total_rows' => 'integer', 'processed_rows' => 'integer', 'parts_count' => 'integer', 'revision' => 'integer', 'attempts' => 'integer', 'read_complete' => 'boolean',
            'lease_expires_at' => 'immutable_datetime', 'prepared_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        ];
    }

    public function percentage(): int
    {
        return $this->status === ExportStatus::COMPLETED ? 100 : ($this->total_rows === 0 ? 0 : min(99, (int) floor($this->processed_rows / $this->total_rows * 100)));
    }
}
