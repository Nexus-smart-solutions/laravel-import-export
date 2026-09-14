<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Fields\RelationField;

final class DataDescription
{
    /** Does not execute option providers or relationship dropdown queries. */
    public function describe(DataDefinition $definition, object $actor): array
    {
        $fields = [];
        foreach ($definition->fieldMap() as $field) {
            if (! $field->canImport && ! $field->inTemplate && ! $definition->canExportField($actor, $field)) {
                continue;
            }
            $fields[] = ['field' => $field->name, 'database_attribute' => $field->databaseColumn, 'import_header' => $field->importHeader,
                'export_header' => $field->exportHeader, 'template_header' => $field->templateHeader, 'required' => $field->isRequired,
                'type' => $field->type, 'aliases' => $field->headerAliases, 'importable' => $field->canImport,
                'exportable' => $definition->canExportField($actor, $field), 'template_visible' => $field->inTemplate,
                'options' => is_array($field->optionSource) ? $field->optionSource : null, 'dynamic_options' => $field->optionSource !== null && ! is_array($field->optionSource),
                'option_input' => $field->optionInput->value, 'example' => $field->sample, 'instructions' => $field->help,
                'relation' => $field instanceof RelationField ? ['name' => $field->relationName, 'lookup' => $field->lookupColumn,
                    'stored' => $field->storedColumn, 'display' => $field->displayColumn, 'template_lookup' => $field->lookupColumn,
                    'label' => $field->labelColumn, 'separator' => $field->labelSeparator] : null];
        }

        return ['fields' => $fields, 'unique_by' => $definition->uniqueBy(), 'filters' => array_keys($definition->filters()), 'sorts' => array_keys($definition->sorts())];
    }
}
