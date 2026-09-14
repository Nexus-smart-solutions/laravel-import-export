<?php

namespace Nexus\ImportExport\Tests\Enterprise;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Contracts\CurrentContext;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\Fields\RelationField;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Services\ImportDispatcher;
use Nexus\ImportExport\TemplateManager;

final class TenantAccessTest extends EnterpriseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('access_schools', function (Blueprint $t): void {
            $t->id();
            $t->integer('tenant_id');
            $t->string('code');
            $t->string('name');
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('access_students', function (Blueprint $t): void {
            $t->id();
            $t->integer('tenant_id');
            $t->string('code');
            $t->string('name');
            $t->foreignId('school_id');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        DB::table('access_schools')->insert([['id' => 17, 'tenant_id' => 1, 'code' => 'SCH001', 'name' => 'School One'], ['id' => 22, 'tenant_id' => 2, 'code' => 'SCH001', 'name' => 'School Two']]);
        config(['bulk-imports.definitions.tenant_students' => TenantStudents::class]);
    }

    public function test_optional_tenant_scopes_relations_templates_exports_and_owner_lists(): void
    {
        $context = new TenantContext;
        $this->app->instance(CurrentContext::class, $context);
        $owner = $this->createActor();
        $import = app(ImportManager::class)->dispatch('tenant_students', $this->csv([['A', 'A', 'SCH001']], ['Code', 'Name', 'School']), $owner);
        self::assertSame(17, (int) DB::table('access_students')->value('school_id'));
        $export = app(ExportManager::class)->dispatch('tenant_students', $owner);
        self::assertStringContainsString('School One', Storage::disk($export->disk)->get($export->file_path));
        $path = app(TemplateManager::class)->generate('tenant_students', $owner);
        try {
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet3.xml');
            $zip->close();
            self::assertStringContainsString('School One', $xml);
            self::assertStringNotContainsString('School Two', $xml);
        } finally {
            unlink($path);
        }
        $context->tenant = 2;
        $this->actingAs($owner)->getJson('/api/data/imports/'.$import->id)->assertForbidden();
        $this->getJson('/api/data/exports/'.$export->id.'/download')->assertForbidden();
        $this->getJson('/api/data/imports')->assertJsonCount(0, 'data');
        $this->getJson('/api/data/exports')->assertJsonCount(0, 'data');
        app(ImportManager::class)->dispatch('tenant_students', $this->csv([['A', 'A', 'SCH001']], ['Code', 'Name', 'School']), $owner);
        self::assertSame(22, (int) DB::table('access_students')->where('tenant_id', 2)->value('school_id'));
    }

    public function test_missing_required_tenant_context_fails_closed(): void
    {
        $this->expectException(ImportConfigurationException::class);
        app(TemplateManager::class)->generate('tenant_students', $this->createActor());
    }

    public function test_direct_dispatch_rejects_forged_tenant_context(): void
    {
        $this->app->instance(CurrentContext::class, new TenantContext);
        $this->expectException(AuthorizationException::class);
        app(ImportDispatcher::class)->dispatchDefinition(TenantStudents::class, $this->csv([['A', 'A', 'SCH001']], ['Code', 'Name', 'School']), $this->createActor(), ['tenant_id' => 2]);
    }
}
final class TenantContext implements CurrentContext
{
    public int $tenant = 1;

    public function get(): array
    {
        return ['tenant_id' => $this->tenant];
    }
}
class AccessSchool extends Model
{
    protected $table = 'access_schools';
}
class AccessStudent extends Model
{
    protected $table = 'access_students';

    public function school(): BelongsTo
    {
        return $this->belongsTo(AccessSchool::class, 'school_id');
    }
}
final class TenantStudents extends DataDefinition
{
    public function model(): string
    {
        return AccessStudent::class;
    }

    public function tenantColumn(): ?string
    {
        return 'tenant_id';
    }

    public function fields(): array
    {
        return [Field::make('code')->excelColumn('Code')->required()->unique()->exportable(), Field::make('name')->excelColumn('Name')->required()->exportable(),
            RelationField::make('school')->column('school_id')->excelColumn('School')->belongsTo('school', AccessSchool::class)->importBy('code')->exportUsing('name')->codeAndLabel()->dropdown()->required()->exportable()];
    }
}
