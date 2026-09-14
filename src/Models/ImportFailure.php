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
 * @property string|null $import_chunk_id
 * @property int|null $row_number
 * @property string|null $column
 * @property string $error_code
 * @property string $error_message
 * @property array|null $original_row_data
 * @property bool $redacted
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $sheet
 * @property int|null $sheet_row
 * @property array|null $normalized_row_data
 * @property string $failure_type
 * @property Import $import
 */
class ImportFailure extends Model
{
    use HasUlids;
    use UsesBulkImportConnection;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('bulk-imports.database.tables.failures', 'import_failures');
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'original_row_data' => 'array',
            'normalized_row_data' => 'array',
            'sheet_row' => 'integer',
            'redacted' => 'boolean',
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
