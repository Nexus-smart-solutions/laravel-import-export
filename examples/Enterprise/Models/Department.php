<?php

namespace Nexus\ImportExport\Examples\Enterprise\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $guarded = [];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
