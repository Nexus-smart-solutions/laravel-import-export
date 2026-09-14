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
 * @property string|null $first_chunk_id
 * @property string $key_hash
 * @property int $first_row_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Import $import
 */
class ImportRowKey extends Model
{
    use HasUlids;
    use UsesBulkImportConnection;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('bulk-imports.database.tables.row_keys', 'import_row_keys');
    }

    protected function casts(): array
    {
        return ['first_row_number' => 'integer'];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
