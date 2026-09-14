<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\Eloquent\Model;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Definitions\Reference;

final class ReferenceResolver
{
    /**
     * @param  list<Reference>  $references
     * @param  list<array<string, mixed>>  $rows
     */
    public function resolve(array $references, array $rows): ResolvedReferences
    {
        $resolved = [];
        $ambiguous = [];

        foreach ($references as $reference) {
            $values = array_values(array_unique(array_filter(
                array_map(static fn (array $row): mixed => $row[$reference->sourceColumn] ?? null, $rows),
                static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '',
            ), SORT_REGULAR));

            $resolved[$reference->name] = [];
            if ($values === []) {
                continue;
            }

            /** @var Model $model */
            $modelClass = $reference->model;
            $model = new $modelClass;
            $parameterBudget = match ($model->getConnection()->getDriverName()) {
                'sqlite' => 900,
                'sqlsrv' => 2000,
                default => 60000,
            };
            $batchSize = max(1, min(
                (int) config('bulk-imports.lookup_batch_size', 500),
                $parameterBudget,
            ));

            foreach (array_chunk($values, $batchSize) as $valueChunk) {
                $query = $model->newQuery()->whereIn($reference->targetColumn, $valueChunk);
                if ($reference->scope !== null) {
                    ($reference->scope)($query);
                }

                foreach ($query->get($reference->select) as $record) {
                    $key = (string) $record->getAttribute($reference->targetColumn);
                    if (isset($resolved[$reference->name][$key]) || isset($ambiguous[$reference->name][$key])) {
                        unset($resolved[$reference->name][$key]);
                        $ambiguous[$reference->name][$key] = true;
                    } else {
                        $resolved[$reference->name][$key] = $record->getAttribute($reference->valueColumn);
                    }
                }
            }
        }

        return new ResolvedReferences($resolved, $ambiguous);
    }
}
