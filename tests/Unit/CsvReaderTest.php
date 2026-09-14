<?php

namespace Nexus\ImportExport\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Readers\CsvReader;
use Nexus\ImportExport\Tests\TestCase;

final class CsvReaderTest extends TestCase
{
    public function test_leading_blank_records_do_not_shift_reported_source_row_numbers(): void
    {
        Storage::disk('imports-test')->put(
            'leading-blank.csv',
            "\nstudent_code,name,email,phone,date_of_birth,course_code\n"
            ."STU-1,One,one@example.test,,2000-01-01,COURSE-01\n",
        );

        $rows = iterator_to_array(
            app(CsvReader::class)->rows('imports-test', 'leading-blank.csv'),
            false,
        );

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->rowNumber);
    }
}
