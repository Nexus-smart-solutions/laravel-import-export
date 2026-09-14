<?php

namespace Nexus\ImportExport\Tests\Feature;

use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Examples\Models\Student;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\Support\ErrorOnDuplicateStudentsImport;
use Nexus\ImportExport\Tests\Support\SkipStudentsImport;
use Nexus\ImportExport\Tests\Support\UpdateStudentsImport;
use Nexus\ImportExport\Tests\TestCase;

final class DuplicateStrategyTest extends TestCase
{
    public function test_error_strategy_converts_an_existing_database_key_to_a_row_failure(): void
    {
        config()->set('bulk-imports.definitions.error_students', ErrorOnDuplicateStudentsImport::class);
        $this->seedCourses();
        Student::query()->create([
            'student_code' => 'STU-1', 'name' => 'Existing', 'email' => 'existing@example.test',
            'course_id' => Course::query()->where('code', 'COURSE-01')->value('id'),
        ]);

        $import = BulkImport::make(ErrorOnDuplicateStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'Replacement', 'new@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
            ]))
            ->by($this->createActor())
            ->dispatch()
            ->refresh();

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $import->status);
        self::assertSame(0, $import->succeeded_rows);
        self::assertSame(1, $import->failed_rows);
        self::assertSame('duplicate_in_database', $import->failures()->value('error_code'));
        self::assertSame('Existing', Student::query()->where('student_code', 'STU-1')->value('name'));
    }

    public function test_skip_strategy_inserts_missing_and_counts_existing_without_updating_it(): void
    {
        config()->set('bulk-imports.definitions.skip_students', SkipStudentsImport::class);
        $this->seedCourses();
        Student::query()->create([
            'student_code' => 'STU-1', 'name' => 'Existing', 'email' => 'existing@example.test',
            'course_id' => Course::query()->where('code', 'COURSE-01')->value('id'),
        ]);

        $import = BulkImport::make(SkipStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'Not Applied', 'new@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-2', 'Inserted', 'two@example.test', '+201000000002', '2000-01-02', 'COURSE-01'],
            ]))->by($this->createActor())->dispatch()->refresh();

        self::assertSame(1, $import->succeeded_rows);
        self::assertSame(1, $import->skipped_rows);
        self::assertSame('Existing', Student::query()->where('student_code', 'STU-1')->value('name'));
        self::assertTrue(Student::query()->where('student_code', 'STU-2')->exists());
    }

    public function test_update_strategy_updates_existing_and_skips_missing_without_inserting_it(): void
    {
        config()->set('bulk-imports.definitions.update_students', UpdateStudentsImport::class);
        $this->seedCourses();
        Student::query()->create([
            'student_code' => 'STU-1', 'name' => 'Existing', 'email' => 'existing@example.test',
            'course_id' => Course::query()->where('code', 'COURSE-01')->value('id'),
        ]);

        $import = BulkImport::make(UpdateStudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'Updated', 'new@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
                ['STU-2', 'Not Inserted', 'two@example.test', '+201000000002', '2000-01-02', 'COURSE-01'],
            ]))->by($this->createActor())->dispatch()->refresh();

        self::assertSame(1, $import->succeeded_rows);
        self::assertSame(1, $import->skipped_rows);
        self::assertSame('Updated', Student::query()->where('student_code', 'STU-1')->value('name'));
        self::assertFalse(Student::query()->where('student_code', 'STU-2')->exists());
    }
}
