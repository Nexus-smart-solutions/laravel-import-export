<?php

namespace Nexus\ImportExport\Examples\Enterprise\Definitions;

use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Examples\Enterprise\Models\Department;
use Nexus\ImportExport\Examples\Enterprise\Models\Employee;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\Fields\RelationField;

final class EmployeeDefinition extends DataDefinition
{
    public function model(): string
    {
        return Employee::class;
    }

    public function fields(): array
    {
        return [
            Field::make('employee_code')->excelColumn('Employee Code')->required()->unique()->exportable(),
            Field::make('name')->excelColumn('Employee Name')->required()->exportable(),
            RelationField::make('department')->column('department_id')->excelColumn('Department')->belongsTo('department', Department::class)
                ->importBy('code')->exportUsing('name')->codeAndLabel()->dropdown()->required()->exportable(),
        ];
    }
}
