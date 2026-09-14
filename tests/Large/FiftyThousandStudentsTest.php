<?php

namespace Nexus\ImportExport\Tests\Large;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Examples\Models\Student;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('performance')]
final class FiftyThousandStudentsTest extends TestCase
{
    public function test_generated_fifty_thousand_row_fixture_is_processed_in_fifty_chunks(): void
    {
        $this->seedCourses();
        Student::query()->create([
            'student_code' => 'STU-00017800',
            'name' => 'Existing Student',
            'email' => 'existing@example.test',
            'course_id' => Course::query()->where('code', 'COURSE-10')->value('id'),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'students-50000-');
        $stream = fopen($path, 'wb');
        fputcsv($stream, ['student_code', 'name', 'email', 'phone', 'date_of_birth', 'course_code'], ',', '"', '', "\n");

        for ($index = 1; $index <= 50000; $index++) {
            $code = sprintf('STU-%08d', $index);
            $email = $index === 17500 ? 'bad-email' : "student{$index}@example.test";
            $course = $index === 17600 ? 'COURSE-MISSING' : sprintf('COURSE-%02d', (($index - 1) % 10) + 1);
            if ($index === 17700) {
                $code = 'STU-00017699';
            }
            fputcsv(
                $stream,
                [$code, "Student {$index}", $email, '+201000000000', '2000-01-01', $course],
                ',',
                '"',
                '',
                "\n",
            );
        }
        fclose($stream);

        try {
            $file = new UploadedFile($path, 'students.csv', 'text/csv', null, true);
            $import = BulkImport::make(StudentsImport::class)
                ->file($file)
                ->by($this->createActor())
                ->options(['chunk_size' => 1000])
                ->dispatch()
                ->refresh();
        } finally {
            @unlink($path);
        }

        self::assertSame(ImportStatus::COMPLETED_WITH_ERRORS, $import->status);
        self::assertSame(50000, $import->total_rows);
        self::assertSame(50, $import->total_chunks);
        self::assertSame(49997, $import->succeeded_rows);
        self::assertSame(3, $import->failed_rows);
        self::assertSame(49997, Student::query()->count());
        self::assertSame('Student 17800', Student::query()->where('student_code', 'STU-00017800')->value('name'));
    }
}
