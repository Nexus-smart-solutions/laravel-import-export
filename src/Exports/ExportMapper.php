<?php

namespace Nexus\ImportExport\Exports;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Fields\RelationField;

final class ExportMapper
{
    /** Bounded query page in, scalar rows out. */
    public function rows(DataDefinition $definition, array $fields, Collection $records, bool $compatible): iterable
    {
        $maps = [];
        $loads = [];
        foreach ($fields as $name) {
            $field = $definition->fieldMap()[$name];
            if ($field instanceof RelationField) {
                $values = $records->pluck($field->databaseColumn)->filter(fn ($v) => $v !== null)->unique()->values()->all();
                $map = [];
                foreach (array_chunk($values, min(500, (int) config('bulk-imports.lookup_batch_size', 500))) as $batch) {
                    $columns = array_values(array_unique([$field->storedColumn, $field->displayColumn, $field->lookupColumn, ...($field->labelColumn ? [$field->labelColumn] : [])]));
                    foreach ($definition->relationQuery($field)->whereIn($field->storedColumn, $batch)->get($columns) as $record) {
                        $key = (string) $record->{$field->storedColumn};
                        if (isset($map[$key])) {
                            throw new ImportConfigurationException('Relation storage keys must be unique.');
                        }
                        $map[$key] = $compatible ? $field->templateValue($record) : $record->{$field->displayColumn};
                    }
                }
                $maps[$name] = $map;
            }
            $paths = $field->eagerLoads;
            if ($field->exportPath !== null && str_contains($field->exportPath, '.')) {
                $paths[] = substr($field->exportPath, 0, strrpos($field->exportPath, '.'));
            }
            foreach ($paths as $path) {
                $model = new ($definition->model());
                $prefix = [];
                foreach (explode('.', $path) as $segment) {
                    $relation = $model->{$segment}();
                    if (! $relation instanceof BelongsTo && ! $relation instanceof HasOne) {
                        throw new ImportConfigurationException('Nested export paths must use bounded singular relations. Preload aggregates in query() for collections.');
                    }
                    $prefix[] = $segment;
                    $loads[implode('.', $prefix)] = fn ($relation) => $definition->scopeQuery($relation->getQuery());
                    $model = $relation->getRelated();
                }
            }
        }
        if ($loads !== []) {
            $records->load($loads);
        }
        foreach ($records as $record) {
            $record->preventsLazyLoading = true;
            $row = [];
            foreach ($fields as $name) {
                $field = $definition->fieldMap()[$name];
                $value = match (true) {
                    $field instanceof RelationField => $maps[$name][(string) $record->{$field->databaseColumn}] ?? null,
                    $field->computer !== null => ($field->computer)($record, $definition->context()),
                    $field->exportPath !== null => data_get($record, $field->exportPath),
                    default => $record->getRawOriginal($field->databaseColumn),
                };
                if (! $field instanceof RelationField) {
                    $value = $field->format($value, $definition->context(), $compatible);
                }
                if (! is_scalar($value) && $value !== null) {
                    throw new ImportConfigurationException('Export values must be scalar or null.');
                }
                if (is_string($value) && strlen($value) > (int) config('bulk-imports.limits.max_cell_length', 65535)) {
                    throw new ImportConfigurationException('Export cell exceeds configured length limit.');
                }
                $row[] = $value;
            }
            yield $row;
        }
    }
}
