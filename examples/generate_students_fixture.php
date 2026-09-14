<?php

declare(strict_types=1);

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

$output = $argv[1] ?? __DIR__.'/students-50000.xlsx';
$rowCount = max(1, (int) ($argv[2] ?? 50000));
$extension = strtolower(pathinfo($output, PATHINFO_EXTENSION));
$headers = ['student_code', 'name', 'email', 'phone', 'date_of_birth', 'course_code'];

if (! in_array($extension, ['csv', 'xlsx'], true)) {
    fwrite(STDERR, "Output must use a .csv or .xlsx extension.\n");
    exit(1);
}

/** @return list<string> */
function studentFixtureRow(int $index): array
{
    $studentCode = sprintf('STU-%08d', $index);
    $email = "student{$index}@example.test";
    $courseCode = sprintf('COURSE-%02d', (($index - 1) % 10) + 1);

    // Deliberately place all interesting rows in Chunk #18 for a 1,000 row chunk size.
    if ($index === 17500) {
        $email = 'not-an-email';
    }
    if ($index === 17600) {
        $courseCode = 'COURSE-MISSING';
    }
    if ($index === 17700) {
        $studentCode = sprintf('STU-%08d', 17699);
    }

    return [
        $studentCode,
        "Student {$index}",
        $email,
        '+201'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
        sprintf(
            '%04d-%02d-%02d',
            1990 + ($index % 15),
            (($index - 1) % 12) + 1,
            (($index - 1) % 28) + 1,
        ),
        $courseCode,
    ];
}

if ($extension === 'xlsx') {
    $autoload = dirname(__DIR__).'/vendor/autoload.php';
    if (! class_exists(Writer::class) && is_file($autoload)) {
        require $autoload;
    }
    if (! class_exists(Writer::class)) {
        fwrite(STDERR, "Run composer install before generating XLSX fixtures.\n");
        exit(1);
    }

    $writer = new Writer;
    $writer->openToFile($output);
    $writer->getCurrentSheet()->setName('Students');
    $writer->addRow(Row::fromValues($headers));
    for ($index = 1; $index <= $rowCount; $index++) {
        $writer->addRow(Row::fromValues(studentFixtureRow($index)));
    }
    $writer->close();
} else {
    $handle = fopen($output, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot open {$output} for writing.\n");
        exit(1);
    }

    fputcsv($handle, $headers, ',', '"', '', "\n");
    for ($index = 1; $index <= $rowCount; $index++) {
        fputcsv($handle, studentFixtureRow($index), ',', '"', '', "\n");
    }
    fclose($handle);
}

fwrite(STDOUT, "Generated {$rowCount} rows at {$output}.\n");
