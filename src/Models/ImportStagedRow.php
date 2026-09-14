<?php

namespace Nexus\ImportExport\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nexus\ImportExport\Models\Concerns\UsesBulkImportConnection;

/**
 * @property string $id
 * @property string $import_id
 * @property string $import_chunk_id
 * @property int $row_number
 * @property string|null $business_key_hash
 * @property array $source_data
 * @property array $payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Import $import
 */
class ImportStagedRow extends Model
{
    use HasUlids;
    use UsesBulkImportConnection;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('bulk-imports.database.tables.staged_rows', 'import_staged_rows');
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'source_data' => 'array',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** @return BelongsTo<ImportChunk, $this> */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(ImportChunk::class, 'import_chunk_id');
    }
}
