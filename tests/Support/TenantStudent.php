<?php

namespace Nexus\ImportExport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class TenantStudent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['organization_id' => 'integer'];
    }
}
