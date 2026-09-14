<?php

namespace Nexus\ImportExport\Examples\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Student extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
