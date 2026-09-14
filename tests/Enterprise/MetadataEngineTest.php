<?php

namespace Nexus\ImportExport\Tests\Enterprise;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Examples\Enterprise\Models\Student;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Exports\ExportRunner;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Models\ExportPart;
use Nexus\ImportExport\TemplateManager;

final class MetadataEngineTest extends EnterpriseTestCase
{
    public function test_one_definition_maps_human_values_to_foreign_keys_and_exports_labels(): void
    {
        $actor = $this->createActor();
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['001', ' Ahmed ', 'Active', 'SCH001 - Future Language School']]), $actor);
        self::assertSame('completed', $import->status->value);
        self::assertSame(1, (int) $import->inserted_rows);
        $student = Student::query()->sole();
        self::assertSame(17, $student->school_id);
        self::assertSame('001', $student->student_code);
        self::assertSame('Ahmed', $student->name);
        $export = app(ExportManager::class)->dispatch('students', $actor, ['fields' => ['student_code', 'name', 'school', 'status']]);
        self::assertSame('completed', $export->status->value);
        $csv = Storage::disk($export->disk)->get($export->file_path);
        self::assertStringContainsString('Future Language School', $csv);
        self::assertStringNotContainsString('school_id', $csv);
    }

    public function test_dry_run_does_not_write_business_rows_and_reports_estimates(): void
    {
        $actor = $this->createActor();
        $file = $this->csv([['001', 'Ahmed', 'active', 'SCH001']]);
        $dry = app(ImportManager::class)->dispatch('students', $file, $actor, ['dry_run' => true]);
        self::assertSame(0, Student::query()->count());
        self::assertSame(1, (int) $dry->would_insert);
        self::assertSame(0, (int) $dry->inserted_rows);
        app(ImportManager::class)->dispatch('students', $file, $actor);
        $updated = app(ImportManager::class)->dispatch('students', $this->csv([['001', 'Changed', 'inactive', 'SCH001']]), $actor);
        self::assertSame(1, (int) $updated->updated_rows);
        self::assertSame('Changed', Student::query()->sole()->name);
    }

    public function test_alias_mapping_validation_missing_relations_and_file_duplicates(): void
    {
        $actor = $this->createActor();
        $file = $this->csv([['001', 'Good', 'Active', 'SCH001'], ['002', 'Bad', 'Unknown', 'SCH001'], ['003', 'Bad', 'active', 'missing'], ['001', 'Duplicate', 'active', 'SCH001']], ['Code', ' FULL_NAME ', 'Status', 'School']);
        $import = app(ImportManager::class)->dispatch('students', $file, $actor, ['mapping' => ['Code' => 'student_code'], 'chunk_size' => 1]);
        self::assertSame('completed_with_errors', $import->status->value);
        self::assertSame(2, $import->failed_rows);
        self::assertSame(1, $import->skipped_rows);
        self::assertSame(4, $import->processed_rows);
        self::assertSame(1, Student::query()->count());
        self::assertNotNull($import->error_report_path);
    }

    public function test_template_contains_instructions_named_ranges_and_human_relation_values(): void
    {
        $path = app(TemplateManager::class)->generate('students', $this->createActor());
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($path));
            self::assertStringContainsString('definedNames', $zip->getFromName('xl/workbook.xml'));
            self::assertStringContainsString('state="hidden"', $zip->getFromName('xl/workbook.xml'));
            self::assertStringContainsString('dataValidations', $zip->getFromName('xl/worksheets/sheet1.xml'));
            self::assertStringContainsString('Student Code', $zip->getFromName('xl/worksheets/sheet1.xml'));
            self::assertStringContainsString('Required', $zip->getFromName('xl/worksheets/sheet2.xml'));
            self::assertStringContainsString('SCH001 - Future Language School', $zip->getFromName('xl/worksheets/sheet3.xml'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_xlsx_update_export_reimports_all_split_data_sheets(): void
    {
        $actor = $this->createActor();
        app(ImportManager::class)->dispatch('students', $this->csv([['001', 'A', 'active', 'SCH001'], ['002', 'B', 'active', 'SCH001'], ['003', 'C', 'active', 'SCH001']]), $actor);
        config(['import-export.xlsx.rows_per_sheet' => 3]);
        $export = app(TemplateManager::class)->withData('students', $actor, ['format' => 'xlsx']);
        self::assertSame('completed', $export->status->value);
        $file = UploadedFile::fake()->createWithContent('update.xlsx', Storage::disk($export->disk)->get($export->file_path));
        $import = app(ImportManager::class)->dispatch('students', $file, $actor);
        self::assertSame('completed', $import->status->value);
        self::assertSame(3, (int) $import->updated_rows);
        self::assertSame(3, Student::query()->count());
    }

    public function test_export_commit_before_ack_retry_and_finalizer_are_idempotent(): void
    {
        $actor = $this->createActor();
        app(ImportManager::class)->dispatch('students', $this->csv([['001', 'A', 'active', 'SCH001'], ['002', 'B', 'active', 'SCH001']]), $actor);
        Queue::fake();
        config(['import-export.exports.query_chunk_size' => 1]);
        $export = app(ExportManager::class)->dispatch('students', $actor);
        $runner = app(ExportRunner::class);
        self::assertTrue($runner->step($export->id, 0));
        self::assertTrue($runner->step($export->id, 1));
        self::assertFalse($runner->step($export->id, 1));
        self::assertSame(1, $export->refresh()->processed_rows);
        while (! $export->refresh()->status->terminal()) {
            $runner->step($export->id, $export->revision);
        }
        self::assertSame(2, $export->processed_rows);
        self::assertFalse($runner->step($export->id, $export->revision));
        self::assertSame(2, ExportPart::query()->count());
    }

    public function test_http_whitelists_fields_downloads_and_unknown_resources(): void
    {
        $actor = $this->createActor();
        $this->actingAs($actor);
        $this->postJson('/api/data/resources/students/exports', ['fields' => ['password']])->assertStatus(422);
        $this->getJson('/api/data/resources/unknown')->assertNotFound();
        $export = app(ExportManager::class)->dispatch('students', $actor);
        $this->actingAs($this->createActor('Other'))->getJson('/api/data/exports/'.$export->id.'/download')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/data/exports/'.$export->id)->assertOk()->assertJsonMissingPath('data.file_path');
    }

    public function test_relation_queries_are_per_chunk_for_three_relations(): void
    {
        $actor = $this->createActor();
        $queries = ['import' => 0, 'export' => 0];
        $phase = 'import';
        DB::listen(function ($event) use (&$queries, &$phase): void {
            if (str_starts_with(strtolower($event->sql), 'select') && preg_match('/from ["`](schools|classrooms|countries)["`]/', $event->sql)) {
                $queries[$phase]++;
            }
        });
        $rows = array_fill(0, 5000, null);
        foreach ($rows as $i => &$row) {
            $row = [sprintf('S%06d', $i), 'A', 'active', 'SCH001', 'CLS01', 'EG'];
        } unset($row);
        config(['bulk-imports.chunk_size' => 5000, 'import-export.exports.query_chunk_size' => 5000]);
        $import = app(ImportManager::class)->dispatch('students', $this->csv($rows, ['Student Code', 'Student Name', 'Status', 'School', 'Class Code', 'Country Code']), $actor);
        self::assertSame('completed', $import->status->value);
        self::assertSame(3, $queries['import']);
        $phase = 'export';
        $export = app(ExportManager::class)->dispatch('students', $actor);
        self::assertSame('completed', $export->status->value);
        self::assertSame(3, $queries['export']);
    }

    public function test_field_visibility_and_explicit_database_excel_mapping(): void
    {
        $field = Field::make('price')->column('unit_price')->importColumn('New Price')->exportColumn('Price')->templateColumn('Upload Price');
        self::assertSame('unit_price', $field->databaseColumn);
        self::assertSame('New Price', $field->importHeader);
        self::assertSame('Upload Price', $field->templateHeader);
        self::assertFalse($field->canExport);
        self::assertFalse(Field::make('secret')->hidden()->canImport);
        self::assertSame('active', Field::make('status')->options(['active' => 'Active'])->normalize('Active', []));
    }
}
