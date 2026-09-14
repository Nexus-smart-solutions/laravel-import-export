<?php

namespace Nexus\ImportExport\Examples\Imports;

use Carbon\CarbonImmutable;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Definitions\EloquentTarget;
use Nexus\ImportExport\Definitions\ImportDefinition;
use Nexus\ImportExport\Definitions\Reference;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Examples\Models\Student;

class StudentsImport extends ImportDefinition
{
    public function columns(): array
    {
        return [
            'student_code',
            'name',
            'email',
            'phone',
            'date_of_birth',
            'course_code',
        ];
    }

    public function rules(): array
    {
        return [
            'student_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\- ]{6,20}$/'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'course_code' => ['required', 'string', 'max:50'],
        ];
    }

    public function uniqueBy(): array
    {
        return ['student_code'];
    }

    public function mode(): ImportMode
    {
        return ImportMode::PARTIAL;
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        // UPSERT means new students are inserted and existing codes are updated.
        return DuplicateStrategy::UPSERT;
    }

    public function target(): EloquentTarget
    {
        return EloquentTarget::for(Student::class, [
            'name',
            'email',
            'phone',
            'date_of_birth',
            'course_id',
            'updated_at',
        ]);
    }

    public function references(): array
    {
        return [
            Reference::make('courses', Course::class, 'course_code', 'code')
                ->value('id')
                ->select(['id', 'code'])
                ->missing('course_not_found', 'The selected course code does not exist.'),
        ];
    }

    public function transform(
        array $row,
        ResolvedReferences $references,
        ImportContext $context,
    ): array {
        $row['course_id'] = $references->get('courses', $row['course_code']);
        if ($row['date_of_birth'] !== null && $row['date_of_birth'] !== '') {
            $row['date_of_birth'] = CarbonImmutable::parse($row['date_of_birth'])->format('Y-m-d');
        }
        unset($row['course_code']);

        return $row;
    }
}
