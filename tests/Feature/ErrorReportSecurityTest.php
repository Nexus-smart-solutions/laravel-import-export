<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportFailure;
use Nexus\ImportExport\Services\ErrorReportGenerator;
use Nexus\ImportExport\Tests\TestCase;

final class ErrorReportSecurityTest extends TestCase
{
    public function test_csv_report_neutralizes_formula_cells_and_uses_redacted_failure_data(): void
    {
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'x.csv',
            'disk' => 'imports-test', 'file_path' => 'x.csv', 'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'status' => ImportStatus::COMPLETED_WITH_ERRORS,
            'deduplication_key' => str_repeat('e', 64),
            'mode' => ImportMode::PARTIAL, 'duplicate_strategy' => DuplicateStrategy::ERROR,
            'context_hash' => str_repeat('c', 64), 'failed_rows' => 1, 'completed_at' => now(),
        ]);
        ImportFailure::query()->create([
            'import_id' => $import->id,
            'row_number' => 2,
            'column' => 'email',
            'error_code' => 'bad_email',
            'error_message' => '=HYPERLINK("https://evil.test")',
            'original_row_data' => ['email' => 'safe@example.test', 'password' => '[REDACTED]'],
            'redacted' => true,
        ]);

        $report = app(ErrorReportGenerator::class)->generate($import);
        $contents = Storage::disk($report['disk'])->get($report['path']);

        self::assertStringContainsString("'=HYPERLINK", $contents);
        self::assertStringContainsString('[REDACTED]', $contents);
    }
}
