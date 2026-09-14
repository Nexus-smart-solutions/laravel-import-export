<?php

namespace Nexus\ImportExport\Examples\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Course extends Model
{
    protected $guarded = [];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
