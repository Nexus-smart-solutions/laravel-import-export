<?php

namespace Nexus\ImportExport\Templates;

use DOMDocument;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\OptionInput;
use Nexus\ImportExport\Fields\RelationField;
use Nexus\ImportExport\Writers\SpreadsheetWriter;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

final class TemplateWriter
{
    public function write(string $path, DataDefinition $definition, string $format): void
    {
        $fields = array_values(array_filter($definition->fieldMap(), fn ($field) => $field->inTemplate));
        $headers = array_map(fn ($field) => $field->templateHeader, $fields);
        $samples = $definition->templateRows();
        if (count($samples) > config('import-export.templates.sample_limit', 100)) {
            throw new \LogicException('Too many configured template examples.');
        }
        $rows = array_map(fn ($row) => array_map(fn ($field) => $row[$field->name] ?? null, $fields), $samples);
        if ($format === 'csv') {
            app(SpreadsheetWriter::class)->write($path, 'csv', $headers, $rows);

            return;
        }
        $lists = [];
        $instructions = [];
        $budget = 0;
        $limit = max(1, min(5000, (int) config('import-export.templates.dropdown_limit', 5000)));
        foreach ($fields as $index => $field) {
            $options = $field->optionValues($definition->context());
            $values = $field->optionInput === OptionInput::VALUE ? array_map(strval(...), array_keys($options)) : array_values($options);
            $note = $field->help;
            if ($field->type === 'boolean' && $values === []) {
                $values = ['Yes', 'No'];
            }
            if ($field instanceof RelationField) {
                $note .= ' Lookup '.$field->relatedModel.'::'.$field->lookupColumn.'; stored '.$field->databaseColumn.' = '.$field->storedColumn.'.';
                if ($field->labelColumn !== null) {
                    $note .= ' Enter code'.$field->labelSeparator.'label; the code is authoritative.';
                }
                if ($field->showDropdown) {
                    $columns = array_values(array_unique([$field->lookupColumn, ...($field->labelColumn ? [$field->labelColumn] : [])]));
                    $records = $definition->relationQuery($field)->orderBy($field->lookupColumn)->limit($limit + 1)->get($columns);
                    if ($records->count() > $limit) {
                        $note .= " Dropdown omitted: more than {$limit} related values. Enter the lookup code.";
                    } else {
                        $codes = [];
                        foreach ($records as $record) {
                            $code = (string) $record->{$field->lookupColumn};
                            if (isset($codes[$code])) {
                                throw new \LogicException('Template relation lookup codes must be unique in scope.');
                            }
                            $codes[$code] = true;
                            $values[] = $field->templateValue($record);
                        }
                    }
                }
            }
            if (count($values) > $limit || $budget + count($values) > config('import-export.templates.total_option_cells', 20000)) {
                $note .= ' Dropdown omitted due to the configured template option budget.';
                $values = [];
            }
            if ($values !== []) {
                $lists[$index] = array_values($values);
                $budget += count($values);
            }
            $instructions[] = [$field->templateHeader, $field->databaseColumn, ($field->isRequired || $field->isUnique) ? 'Yes' : 'No', $field->type,
                $field->sample ?? '', $note, $options === [] ? '' : implode(', ', array_slice($values, 0, 25))];
        }
        $options = new Options();
        $options->DEFAULT_COLUMN_WIDTH = 24;
        $writer = new Writer($options);
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Data 1');
            $writer->addRow(SpreadsheetWriter::row($headers));
            foreach ($rows as $row) {
                $writer->addRow(SpreadsheetWriter::row($row));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
            $writer->addRow(SpreadsheetWriter::row(['Column', 'Database attribute', 'Required', 'Type', 'Example', 'Instructions', 'Allowed values (preview)']));
            foreach ($instructions as $row) {
                $writer->addRow(SpreadsheetWriter::row($row));
            }
            if ($lists !== []) {
                $writer->addNewSheetAndMakeItCurrent()->setName('__options');
                // One vertical column per list; the bounded template is the only workbook edited using DOM.
                $maxRows = max(array_map(count(...), $lists));
                $width = count($lists);
                if ($width * $maxRows > config('import-export.templates.total_option_cells', 20000)) {
                    // Pack lists vertically instead: named ranges refer to successive segments of column A.
                    foreach ($lists as $values) {
                        foreach ($values as $value) {
                            $writer->addRow(SpreadsheetWriter::row([$value]));
                        }
                    }
                } else {
                    for ($row = 0; $row < $maxRows; $row++) {
                        $writer->addRow(SpreadsheetWriter::row(array_map(fn ($values) => $values[$row] ?? '', array_values($lists))));
                    }
                }
            }
        } finally {
            $writer->close();
        }
        if ($lists !== []) {
            $this->addValidations($path, $lists, $fields);
        }
    }

    private function column(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function addValidations(string $path, array $lists, array $fields): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot update template validation metadata.');
        }
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        try {
            $workbook = new DOMDocument;
            $workbook->loadXML($zip->getFromName('xl/workbook.xml'), LIBXML_NONET);
            $sheet = new DOMDocument;
            $sheet->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
            foreach ($workbook->getElementsByTagNameNS($ns, 'sheet') as $node) {
                if ($node->getAttribute('name') === '__options') {
                    $node->setAttribute('state', 'hidden');
                }
            }
            $names = $workbook->createElementNS($ns, 'definedNames');
            $validations = $sheet->createElementNS($ns, 'dataValidations');
            $validations->setAttribute('count', (string) count($lists));
            $packed = count($lists) * max(array_map(count(...), $lists)) > config('import-export.templates.total_option_cells', 20000);
            $listIndex = 0;
            $offset = 1;
            foreach ($lists as $index => $values) {
                $name = 'FieldOptions_'.$index;
                $column = $this->column($packed ? 0 : $listIndex++);
                $start = $packed ? $offset : 1;
                $end = $start + count($values) - 1;
                $offset = $end + 1;
                $defined = $workbook->createElementNS($ns, 'definedName');
                $defined->setAttribute('name', $name);
                $defined->appendChild($workbook->createTextNode("'__options'!\${$column}\${$start}:\${$column}\${$end}"));
                $names->appendChild($defined);
                $validation = $sheet->createElementNS($ns, 'dataValidation');
                foreach (['type' => 'list', 'allowBlank' => $fields[$index]->isRequired ? '0' : '1', 'showErrorMessage' => '1', 'errorStyle' => 'stop', 'errorTitle' => 'Invalid value', 'error' => 'Choose a listed value.'] as $key => $value) {
                    $validation->setAttribute($key, $value);
                }
                $last = max(2, min(1048576, (int) config('import-export.templates.validation_rows', 10000)));
                $validation->setAttribute('sqref', $this->column($index).'2:'.$this->column($index).$last);
                $formula = $sheet->createElementNS($ns, 'formula1');
                $formula->appendChild($sheet->createTextNode($name));
                $validation->appendChild($formula);
                $validations->appendChild($validation);
            }
            $calc = $workbook->getElementsByTagNameNS($ns, 'calcPr')->item(0);
            if ($calc) {
                $workbook->documentElement->insertBefore($names, $calc);
            } else {
                $workbook->documentElement->appendChild($names);
            }
            $sheetData = $sheet->getElementsByTagNameNS($ns, 'sheetData')->item(0);
            $sheet->documentElement->insertBefore($validations, $sheetData->nextSibling);
            $zip->addFromString('xl/workbook.xml', $workbook->saveXML());
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet->saveXML());
        } finally {
            $zip->close();
        }
    }
}
