<?php

namespace Nexus\ImportExport\Definitions;

use Illuminate\Database\Eloquent\Model;
use Nexus\ImportExport\Contracts\ImportTarget;
use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Support\BusinessKey;

final class EloquentTarget implements ImportTarget
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $updateColumns
     */
    public function __construct(
        private readonly string $model,
        private readonly array $updateColumns,
        private readonly ?string $connection = null,
    ) {}

    public static function for(string $model, array $updateColumns, ?string $connection = null): self
    {
        return new self($model, array_values($updateColumns), $connection);
    }

    public function connectionName(ImportContext $context): ?string
    {
        return $this->connection ?? $this->newModel()->getConnectionName();
    }

    public function lockScope(ImportContext $context): string
    {
        $model = $this->newModel();

        return implode('|', [$model->getConnectionName() ?? 'default', $model->getTable()]);
    }

    public function existingKeyHashes(
        array $rows,
        ImportDefinition $definition,
        ImportContext $context,
    ): array {
        if ($rows === []) {
            return [];
        }

        $model = $this->newModel();
        $query = $model->newQuery();
        $uniqueBy = $definition->targetUniqueBy();
        $driver = $model->getConnection()->getDriverName();
        $parameterBudget = match ($driver) {
            'sqlite' => 900,
            'sqlsrv' => 2000,
            default => 60000,
        };
        $lookupBatchSize = max(1, min(
            (int) config('bulk-imports.lookup_batch_size', 500),
            intdiv($parameterBudget, max(1, count($uniqueBy))),
        ));
        $hashes = [];

        if (count($uniqueBy) === 1) {
            $column = $uniqueBy[0];
            $values = array_values(array_unique(array_map(
                static fn (array $row): mixed => $row[$column] ?? null,
                $rows,
            ), SORT_REGULAR));

            foreach (array_chunk($values, $lookupBatchSize) as $valueChunk) {
                foreach ((clone $query)->whereIn($column, $valueChunk)->get($uniqueBy) as $existing) {
                    $hashes[$definition->targetBusinessKeyHash($existing->getAttributes())] = true;
                }
            }

            return $hashes;
        }

        $uniqueRows = [];
        foreach ($rows as $row) {
            $uniqueRows[$definition->targetBusinessKeyHash($row)] = BusinessKey::values($row, $uniqueBy);
        }

        foreach (array_chunk(array_values($uniqueRows), $lookupBatchSize) as $valueChunk) {
            $existing = (clone $query)
                ->where(function ($outer) use ($valueChunk): void {
                    foreach ($valueChunk as $values) {
                        $outer->orWhere(function ($inner) use ($values): void {
                            foreach ($values as $column => $value) {
                                $inner->where($column, $value);
                            }
                        });
                    }
                })
                ->get($uniqueBy);

            foreach ($existing as $record) {
                $hashes[$definition->targetBusinessKeyHash($record->getAttributes())] = true;
            }
        }

        return $hashes;
    }

    public function write(
        array $rows,
        array $uniqueBy,
        DuplicateStrategy $strategy,
        ImportContext $context,
    ): BatchWriteResult {
        if ($rows === []) {
            return new BatchWriteResult(0);
        }

        $model = $this->newModel();
        $rows = $this->addTimestamps($rows, $model);

        foreach (array_chunk($rows, $this->safeWriteBatchSize($model, $rows[0])) as $batch) {
            match ($strategy) {
                DuplicateStrategy::ERROR, DuplicateStrategy::SKIP => $model->newQuery()->insert($batch),
                DuplicateStrategy::UPDATE, DuplicateStrategy::UPSERT => $model->newQuery()->upsert(
                    $batch,
                    $uniqueBy,
                    array_values(array_intersect($this->updateColumns, array_keys($batch[0]))),
                ),
            };
        }

        return BatchWriteResult::allSucceeded(count($rows));
    }

    private function newModel(): Model
    {
        $modelClass = $this->model;
        /** @var Model $model */
        $model = new $modelClass;

        if ($this->connection !== null) {
            $model->setConnection($this->connection);
        }

        return $model;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function addTimestamps(array $rows, Model $model): array
    {
        if (! $model->usesTimestamps()) {
            return $rows;
        }

        $now = $model->freshTimestampString();

        foreach ($rows as &$row) {
            $row[$model->getUpdatedAtColumn()] ??= $now;
            $row[$model->getCreatedAtColumn()] ??= $now;
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function safeWriteBatchSize(Model $model, array $row): int
    {
        $budget = match ($model->getConnection()->getDriverName()) {
            'sqlite' => 900,
            'sqlsrv' => 2000,
            default => 60000,
        };
        $byParameters = max(1, intdiv($budget, max(1, count($row))));

        return max(1, min((int) config('bulk-imports.write_batch_size', 1000), $byParameters));
    }
}
