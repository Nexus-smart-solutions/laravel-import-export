<?php

namespace Nexus\ImportExport\Tests\Support;

use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Definitions\EloquentTarget;
use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Enums\DuplicateStrategy;

final class TenantStudentsImport extends ImportDefinition
{
    public function columns(): array
    {
        return ['student_code', 'name'];
    }

    public function rules(): array
    {
        return ['student_code' => ['required', 'string'], 'name' => ['required', 'string']];
    }

    public function uniqueBy(): array
    {
        return ['student_code'];
    }

    public function targetUniqueBy(): array
    {
        return ['organization_id', 'student_code'];
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::UPSERT;
    }

    public function target(): EloquentTarget
    {
        return EloquentTarget::for(TenantStudent::class, ['name', 'updated_at']);
    }

    public function transform(
        array $row,
        ResolvedReferences $references,
        ImportContext $context,
    ): array {
        $row['organization_id'] = (int) $context->contextValue('organization_id');

        return $row;
    }
}
