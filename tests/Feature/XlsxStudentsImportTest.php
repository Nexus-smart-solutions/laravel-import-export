<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Examples\Models\Student;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\TestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class XlsxStudentsImportTest extends TestCase
{
    public function test_xlsx_adapter_streams_a_real_workbook_through_the_shared_pipeline(): void
    {
        $this->seedCourses();
        $path = tempnam(sys_get_temp_dir(), 'students-xlsx-');
        self::assertIsString($path);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Students');
        $writer->addRow(Row::fromValues([
            'student_code', 'name', 'email', 'phone', 'date_of_birth', 'course_code',
        ]));
        $writer->addRow(Row::fromValues([
            'STU-XLSX-1', 'XLSX Student', 'xlsx@example.test', '+201000000001', '2000-01-01', 'COURSE-01',
        ]));
        $writer->close();

        try {
            $file = new UploadedFile(
                $path,
                'students.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            );
            $import = BulkImport::make(StudentsImport::class)
                ->file($file)
                ->by($this->createActor())
                ->dispatch()
                ->refresh();
        } finally {
            @unlink($path);
        }

        self::assertSame(ImportStatus::COMPLETED, $import->status);
        self::assertSame(1, $import->succeeded_rows);
        self::assertTrue(Student::query()->where('student_code', 'STU-XLSX-1')->exists());
    }

    public function test_xlsx_adapter_skips_an_empty_leading_sheet_when_no_sheet_is_required(): void
    {
        $this->seedCourses();
        $path = tempnam(sys_get_temp_dir(), 'students-xlsx-sheets-');
        self::assertIsString($path);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Empty');
        $writer->addNewSheetAndMakeItCurrent()->setName('Students');
        $writer->addRow(Row::fromValues([
            'student_code', 'name', 'email', 'phone', 'date_of_birth', 'course_code',
        ]));
        $writer->addRow(Row::fromValues([
            'STU-XLSX-2', 'Second Sheet', 'second@example.test', '+201000000002', '2000-01-02', 'COURSE-02',
        ]));
        $writer->close();

        try {
            $import = BulkImport::make(StudentsImport::class)
                ->file(new UploadedFile($path, 'students.xlsx', null, null, true))
                ->by($this->createActor())
                ->dispatch()
                ->refresh();
        } finally {
            @unlink($path);
        }

        self::assertSame(ImportStatus::COMPLETED, $import->status);
        self::assertTrue(Student::query()->where('student_code', 'STU-XLSX-2')->exists());
    }
}
