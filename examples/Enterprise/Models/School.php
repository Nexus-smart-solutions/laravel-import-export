<?php

namespace Nexus\ImportExport\Examples\Enterprise\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $guarded = [];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
