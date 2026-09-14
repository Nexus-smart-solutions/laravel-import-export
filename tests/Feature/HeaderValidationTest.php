<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Enums\FailureStage;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Jobs\PrepareImportJob;
use Nexus\ImportExport\Tests\TestCase;

final class HeaderValidationTest extends TestCase
{
    public function test_missing_mandatory_header_is_an_import_failure_not_a_row_failure(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent(
            'students.csv',
            "student_code,name,email\nSTU-1,One,one@example.test\n",
        );
        $import = BulkImport::make(StudentsImport::class)
            ->file($file)->by($this->createActor())->dispatch();
        $job = new PrepareImportJob($import->id, []);

        try {
            app()->call([$job, 'handle']);
            self::fail('Header validation should fail.');
        } catch (FileValidationException $exception) {
            $job->failed($exception);
        }

        $import->refresh();
        self::assertSame(ImportStatus::FAILED, $import->status);
        self::assertSame(FailureStage::PREPARATION, $import->failure_stage);
        self::assertSame(0, $import->failures()->count());
        self::assertStringContainsString('Missing mandatory headers', $import->error_message);
    }

    public function test_corrupt_xlsx_is_a_preparation_failure_not_completed_with_row_errors(): void
    {
        Queue::fake();
        $import = BulkImport::make(StudentsImport::class)
            ->file(UploadedFile::fake()->createWithContent('students.xlsx', 'not-a-zip-container'))
            ->by($this->createActor())
            ->dispatch();
        $job = new PrepareImportJob($import->id, []);

        try {
            app()->call([$job, 'handle']);
            self::fail('A corrupt workbook should fail structure validation.');
        } catch (FileValidationException $exception) {
            $job->failed($exception);
        }

        $import->refresh();
        self::assertSame(ImportStatus::FAILED, $import->status);
        self::assertSame(FailureStage::PREPARATION, $import->failure_stage);
        self::assertSame(0, $import->failed_rows);
    }

    public function test_stored_source_is_rejected_if_it_changes_after_acceptance(): void
    {
        Queue::fake();
        $import = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
            ]))
            ->by($this->createActor())
            ->dispatch();
        Storage::disk($import->disk)->put($import->file_path, 'tampered');
        $job = new PrepareImportJob($import->id, []);

        try {
            app()->call([$job, 'handle']);
            self::fail('The accepted upload checksum must be enforced.');
        } catch (FileValidationException $exception) {
            self::assertStringContainsString('checksum', $exception->getMessage());
            $job->failed($exception);
        }

        self::assertSame(ImportStatus::FAILED, $import->refresh()->status);
    }
}
