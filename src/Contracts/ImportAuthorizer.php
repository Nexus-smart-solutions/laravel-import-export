<?php

namespace Nexus\ImportExport\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nexus\ImportExport\Models\Import;

interface ImportAuthorizer
{
    /** @param array<string, scalar|null> $context */
    public function canCreate(?object $actor, string $type, array $context): bool;

    public function canView(?object $actor, Import $import): bool;

    public function canCancel(?object $actor, Import $import): bool;

    public function canRetry(?object $actor, Import $import): bool;

    /**
     * Apply mandatory ownership/tenant constraints before any user filters.
     *
     * @param  Builder<Import>  $query
     * @return Builder<Import>
     */
    public function scopeVisible(Builder $query, ?object $actor): Builder;
}
