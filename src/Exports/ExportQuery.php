<?php

namespace Nexus\ImportExport\Exports;

use Illuminate\Database\Eloquent\Builder;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Models\Export;

final class ExportQuery
{
    public function base(DataDefinition $definition, array $request): Builder
    {
        $query = $definition->scopeQuery($definition->query());
        foreach ($request['filters'] ?? [] as $name => $value) {
            ($definition->filters()[$name])($query, $value, $definition->context());
        }
        if (($request['ids'] ?? []) !== []) {
            $query->whereKey($request['ids']);
        }
        $raw = $query->getQuery();
        if ($raw->groups || $raw->havings || $raw->unions || $raw->limit || $raw->offset || $raw->joins) {
            throw new ImportConfigurationException('Export queries require one root row per primary key; joins/grouping/offset/limits are unsupported. Use subselects and withCount for aggregates.');
        }

        return $query->withoutEagerLoads()->reorder();
    }

    public function sort(DataDefinition $definition, array $request): array
    {
        $key = (new ($definition->model()))->getKeyName();

        return [$request['sort'] === null ? $key : $definition->sorts()[$request['sort']], $key, $request['direction']];
    }

    public function page(DataDefinition $definition, Export $export): Builder
    {
        $query = $this->base($definition, $export->request);
        [$sort, $key, $direction] = $this->sort($definition, $export->request);
        $qsort = $query->getModel()->qualifyColumn($sort);
        $qkey = $query->getModel()->qualifyColumn($key);
        $query->where($qkey, '<=', $export->high_water);
        if ($export->checkpoint !== null) {
            $checkpoint = $export->checkpoint;
            $operator = $direction === 'asc' ? '>' : '<';
            $query->where(function ($q) use ($checkpoint, $qsort, $qkey, $operator, $sort, $key): void {
                if ($sort === $key) {
                    $q->where($qkey, $operator, $checkpoint['key']);
                } else {
                    $q->where($qsort, $operator, $checkpoint['sort'])->orWhere(fn ($q) => $q->where($qsort, $checkpoint['sort'])->where($qkey, $operator, $checkpoint['key']));
                }
            });
        }
        $query->orderBy($qsort, $direction);
        if ($sort !== $key) {
            $query->orderBy($qkey, $direction);
        }

        return $query->limit(max(1, (int) config('import-export.exports.query_chunk_size', 2000)));
    }
}
