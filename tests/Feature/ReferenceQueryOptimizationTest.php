<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Tests\TestCase;

final class ReferenceQueryOptimizationTest extends TestCase
{
    public function test_course_references_are_loaded_once_for_the_chunk_instead_of_once_per_row(): void
    {
        $this->seedCourses();
        $courseSelects = 0;
        DB::listen(function ($query) use (&$courseSelects): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "courses"') || str_contains($sql, 'from `courses`')) {
                $courseSelects++;
            }
        });
        $rows = [];
        foreach (range(1, 1000) as $index) {
            $rows[] = [
                "STU-{$index}",
                "Student {$index}",
                "student{$index}@example.test",
                '+201000000000',
                '2000-01-01',
                sprintf('COURSE-%02d', (($index - 1) % 10) + 1),
            ];
        }

        BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))
            ->by($this->createActor())
            ->options(['chunk_size' => 1000])
            ->dispatch();

        self::assertSame(1, $courseSelects);
    }
}
