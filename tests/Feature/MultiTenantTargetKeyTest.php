<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\Support\TenantStudent;
use Nexus\ImportExport\Tests\Support\TenantStudentsImport;
use Nexus\ImportExport\Tests\TestCase;

final class MultiTenantTargetKeyTest extends TestCase
{
    public function test_context_injected_target_key_matches_composite_database_constraint(): void
    {
        config()->set('bulk-imports.definitions.tenant_students', TenantStudentsImport::class);
        $actor = $this->createActor();

        foreach ([10, 20] as $organizationId) {
            BulkImport::make(TenantStudentsImport::class)
                ->file(UploadedFile::fake()->createWithContent(
                    'tenant-students.csv',
                    "student_code,name\nSTU-1,Organization {$organizationId} Student\n",
                ))
                ->by($actor)
                ->context(['organization_id' => $organizationId])
                ->dispatch();
        }

        self::assertSame(2, TenantStudent::query()->where('student_code', 'STU-1')->count());
        self::assertSame(
            [10, 20],
            TenantStudent::query()->orderBy('organization_id')->pluck('organization_id')->all(),
        );
    }
}
