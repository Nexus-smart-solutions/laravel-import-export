<?php

namespace Nexus\ImportExport\Examples\Enterprise\Definitions;

use Illuminate\Validation\Rule;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Examples\Enterprise\Models\Classroom;
use Nexus\ImportExport\Examples\Enterprise\Models\Country;
use Nexus\ImportExport\Examples\Enterprise\Models\School;
use Nexus\ImportExport\Examples\Enterprise\Models\Student;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\Fields\RelationField;

class StudentDefinition extends DataDefinition
{
    public function model(): string
    {
        return Student::class;
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::UPSERT;
    }

    public function fields(): array
    {
        return [
            Field::make('student_code')->excelColumn('Student Code')->required()->unique()->rules(['max:50'])->example('STD0001')->exportable(),
            Field::make('name')->excelColumn('Student Name')->required()->aliases(['Name', 'Full Name', 'student_name'])->rules(['max:255'])->exportable(),
            Field::make('email')->excelColumn('Email')->email()->nullable()->rules(['max:255'])->exportable(),
            Field::make('phone')->excelColumn('Phone')->phone()->nullable()->rules(['max:50'])->exportable(),
            Field::make('birth_date')->column('date_of_birth')->excelColumn('Birth Date')->date()->nullable()->exportable(),
            Field::make('status')->excelColumn('Status')->required()->options([
                'active' => 'Active', 'inactive' => 'Inactive', 'graduated' => 'Graduated', 'suspended' => 'Suspended',
            ])->exportable(),
            RelationField::make('school')->column('school_id')->excelColumn('School')
                ->aliases(['School Code'])->belongsTo('school', School::class)->importBy('code')->storeUsing('id')
                ->exportUsing('name')->templateUsing('code')->codeAndLabel('name')->dropdown()->required()->exportable(),
            RelationField::make('classroom')->column('classroom_id')->excelColumn('Class Code')->exportColumn('Class')
                ->belongsTo('classroom', Classroom::class)->importBy('code')->exportUsing('name')->nullable()->dropdown()->exportable(),
            RelationField::make('country')->column('country_id')->excelColumn('Country Code')->exportColumn('Country')
                ->belongsTo('country', Country::class)->importBy('code')->exportUsing('name')->nullable()->dropdown()->exportable(),
            Field::make('active')->excelColumn('Active')->boolean()->options([1 => 'Yes', 0 => 'No'])->default(1)->exportable(),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => function ($query, $value): void {
                validator(['status' => $value], ['status' => ['required', Rule::in(['active', 'inactive', 'graduated', 'suspended'])]])->validate();
                $query->where('status', $value);
            },
            'school_id' => function ($query, $value): void {
                validator(['school_id' => $value], ['school_id' => ['required', 'integer']])->validate();
                $query->where('school_id', $value);
            },
        ];
    }

    public function sorts(): array
    {
        return ['student_code' => 'student_code'];
    }
}
