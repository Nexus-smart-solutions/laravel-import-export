<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportFailure;
use Nexus\ImportExport\Models\ImportRowKey;
use Nexus\ImportExport\Tests\TestCase;

final class CleanupCommandTest extends TestCase
{
    public function test_cleanup_enforces_source_report_and_failure_record_retention(): void
    {
        config()->set('bulk-imports.files.retention_days', 0);
        config()->set('bulk-imports.failures.retention_days', 0);
        config()->set('bulk-imports.failures.records_retention_days', 0);
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'students.csv',
            'disk' => 'imports-test', 'file_path' => 'bulk-imports/incoming/source.csv',
            'file_hash' => str_repeat('a', 64), 'fingerprint' => str_repeat('b', 64),
            'deduplication_key' => str_repeat('e', 64),
            'status' => ImportStatus::COMPLETED_WITH_ERRORS, 'mode' => ImportMode::PARTIAL,
            'duplicate_strategy' => DuplicateStrategy::UPSERT, 'context_hash' => str_repeat('c', 64),
            'failed_rows' => 1, 'completed_at' => now()->subMinute(),
            'error_report_disk' => 'imports-test',
            'error_report_path' => 'bulk-imports/reports/report.csv',
        ]);
        Storage::disk('imports-test')->put($import->file_path, 'source');
        Storage::disk('imports-test')->put("bulk-imports/chunks/{$import->id}/00000001.jsonl", '{}');
        Storage::disk('imports-test')->put($import->error_report_path, 'report');
        ImportFailure::query()->create([
            'import_id' => $import->id, 'row_number' => 2, 'column' => 'email',
            'error_code' => 'invalid', 'error_message' => 'Invalid.',
        ]);
        ImportRowKey::query()->create([
            'import_id' => $import->id, 'key_hash' => str_repeat('d', 64), 'first_row_number' => 2,
        ]);

        $this->artisan('bulk-imports:cleanup')->assertSuccessful();

        Storage::disk('imports-test')->assertMissing('bulk-imports/incoming/source.csv');
        Storage::disk('imports-test')->assertMissing('bulk-imports/reports/report.csv');
        self::assertSame(0, ImportFailure::query()->where('import_id', $import->id)->count());
        self::assertSame(0, ImportRowKey::query()->where('import_id', $import->id)->count());
        $import->refresh();
        self::assertNotNull($import->files_deleted_at);
        self::assertNotNull($import->failure_records_deleted_at);
        self::assertNull($import->error_report_path);
    }
}
