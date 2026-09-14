<?php

namespace Nexus\ImportExport\Tests\Feature;

use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\AtomicStudentsImport;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Examples\Models\Student;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\Support\AtomicErrorStudentsImport;
use Nexus\ImportExport\Tests\TestCase;

final class AtomicStudentsImportTest extends TestCase
{
    public function test_atomic_import_merges_all_staged_rows_in_one_final_transaction(): void
    {
        $this->seedCourses();

        $import = BulkImport::make(AtomicStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-2', 'Two', 'two@example.test', '+201000000002', '2000-01-02', 'COURSE-02'],
            ]))
            ->by($this->createActor())
            ->options(['chunk_size' => 1])
            ->dispatch()
            ->refresh();

        self::assertSame(ImportStatus::COMPLETED, $import->status);
        self::assertSame(2, $import->succeeded_rows);
        self::assertSame(0, $import->staged_rows);
        self::assertSame(2, Student::query()->count());
        self::assertSame(0, $import->stagedRows()->count());
    }

    public function test_atomic_import_with_one_invalid_row_writes_nothing_to_production(): void
    {
        $this->seedCourses();

        $import = BulkImport::make(AtomicStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-2', 'Bad', 'bad-email', '+201000000002', '2000-01-02', 'COURSE-02'],
            ]))
            ->by($this->createActor())
            ->dispatch()
            ->refresh();

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $import->status);
        self::assertSame(0, $import->succeeded_rows);
        self::assertSame(1, $import->failed_rows);
        self::assertSame(1, $import->staged_rows);
        self::assertSame(0, Student::query()->count());
        self::assertSame(0, $import->stagedRows()->count());
    }

    public function test_atomic_commit_time_duplicate_rejection_rolls_back_every_production_write(): void
    {
        config()->set('bulk-imports.definitions.atomic_error_students', AtomicErrorStudentsImport::class);
        $this->seedCourses();
        Student::query()->create([
            'student_code' => 'STU-EXISTING',
            'name' => 'Original',
            'email' => 'original@example.test',
            'course_id' => Course::query()->where('code', 'COURSE-01')->value('id'),
        ]);

        $import = BulkImport::make(AtomicErrorStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-NEW', 'New', 'new@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-EXISTING', 'Replacement', 'replacement@example.test', '+201000000002', '2000-01-02', 'COURSE-01'],
            ]))
            ->by($this->createActor())
            ->dispatch()
            ->refresh();

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $import->status);
        self::assertSame(1, $import->failed_rows);
        self::assertSame('duplicate_in_database', $import->failures()->value('error_code'));
        self::assertSame(1, Student::query()->count());
        self::assertFalse(Student::query()->where('student_code', 'STU-NEW')->exists());
        self::assertSame('Original', Student::query()->where('student_code', 'STU-EXISTING')->value('name'));
        self::assertSame(0, $import->stagedRows()->count());
    }
}
