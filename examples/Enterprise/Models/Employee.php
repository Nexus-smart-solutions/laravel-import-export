<?php

namespace Nexus\ImportExport\Examples\Enterprise\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $guarded = [];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
