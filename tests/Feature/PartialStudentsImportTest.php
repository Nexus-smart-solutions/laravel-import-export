<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Examples\Models\Student;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\TestCase;

final class PartialStudentsImportTest extends TestCase
{
    public function test_partial_import_records_bad_rows_updates_existing_and_finishes_with_errors(): void
    {
        $this->seedCourses();
        $course = Course::query()->where('code', 'COURSE-01')->firstOrFail();
        Student::query()->create([
            'student_code' => 'STU-5',
            'name' => 'Old Name',
            'email' => 'old@example.test',
            'course_id' => $course->id,
        ]);

        $import = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'Student One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-2', 'Bad Email', 'not-an-email', '+201000000002', '2000-01-02', 'COURSE-01'],
                ['STU-3', 'Missing Course', 'three@example.test', '+201000000003', '2000-01-03', 'MISSING'],
                ['STU-1', 'Duplicate File Key', 'dupe@example.test', '+201000000004', '2000-01-04', 'COURSE-01'],
                ['STU-5', 'Updated Name', 'updated@example.test', '+201000000005', '2000-01-05', 'COURSE-01'],
            ]))
            ->by($this->createActor())
            ->context(['tenant_id' => 1])
            ->options(['chunk_size' => 2])
            ->dispatch()
            ->refresh();

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $import->status);
        self::assertSame(5, $import->total_rows);
        self::assertSame(5, $import->processed_rows);
        self::assertSame(2, $import->succeeded_rows);
        self::assertSame(3, $import->failed_rows);
        self::assertSame(0, $import->skipped_rows);
        self::assertSame(100, $import->percentage());
        self::assertSame(3, $import->total_chunks);
        self::assertSame(3, $import->failures()->count());
        self::assertSame(
            ['course_not_found', 'duplicate_in_file', 'validation_email'],
            $import->failures()->pluck('error_code')->sort()->values()->all(),
        );
        self::assertSame(2, Student::query()->count());
        self::assertSame('Updated Name', Student::query()->where('student_code', 'STU-5')->value('name'));
        self::assertNotNull($import->error_report_path);
        Storage::disk('imports-test')->assertExists($import->error_report_path);
    }
}
