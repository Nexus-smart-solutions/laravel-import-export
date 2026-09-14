<?php

namespace Nexus\ImportExport\Definitions;

use Illuminate\Database\Eloquent\Model;
use Nexus\ImportExport\Contracts\AtomicCommitter;
use Nexus\ImportExport\Contracts\ImportTarget;
use Nexus\ImportExport\Data\BatchWriteResult;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Support\BusinessKey;

abstract class ImportDefinition
{
    /** @return list<string> */
    abstract public function columns(): array;

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @return list<string> */
    abstract public function uniqueBy(): array;

    public function requiredColumns(): array
    {
        return $this->columns();
    }

    public function normalize(array $row): array
    {
        return $row;
    }

    public function mode(): ImportMode
    {
        return ImportMode::PARTIAL;
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::ERROR;
    }

    /** Bump when definition semantics change and old submissions must not deduplicate. */
    public function version(): string
    {
        return '1';
    }

    /** @return list<string> Target key after transform/context injection. */
    public function targetUniqueBy(): array
    {
        return $this->uniqueBy();
    }

    /** @param array<string, mixed> $row */
    public function sourceBusinessKeyHash(array $row): string
    {
        return BusinessKey::hash($row, $this->uniqueBy());
    }

    /** @param array<string, mixed> $row */
    public function targetBusinessKeyHash(array $row): string
    {
        return BusinessKey::hash($row, $this->targetUniqueBy());
    }

    public function target(): ?ImportTarget
    {
        return null;
    }

    /** @return list<Reference> */
    public function references(): array
    {
        return [];
    }

    /**
     * Map a validated source row into a production payload. No database queries
     * should be made here; use references() so lookups are loaded per chunk.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function transform(
        array $row,
        ResolvedReferences $references,
        ImportContext $context,
    ): array {
        return $row;
    }

    /**
     * Override for a custom writer. The default delegates to target().
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function handleBatch(array $rows, ImportContext $context): BatchWriteResult
    {
        $target = $this->target()
            ?? throw new ImportConfigurationException(static::class.' must define target() or override handleBatch().');

        return $target->write($rows, $this->targetUniqueBy(), $this->duplicateStrategy(), $context);
    }

    public function atomicCommitter(): ?AtomicCommitter
    {
        return null;
    }

    public function worksheet(): ?string
    {
        return null;
    }

    /** @return array<string, string> source header => canonical header */
    public function headerAliases(): array
    {
        return [];
    }

    public function validateConfiguration(): void
    {
        $columns = $this->columns();
        $uniqueBy = $this->uniqueBy();
        $targetUniqueBy = $this->targetUniqueBy();

        if ($columns === []
            || ! array_is_list($columns)
            || collect($columns)->contains(static fn (mixed $column): bool => ! is_string($column) || trim($column) === '')
            || count($columns) !== count(array_unique($columns))) {
            throw new ImportConfigurationException(static::class.' columns must be non-empty and unique.');
        }

        if ($uniqueBy === []
            || ! array_is_list($uniqueBy)
            || collect($uniqueBy)->contains(static fn (mixed $column): bool => ! is_string($column))
            || array_diff($uniqueBy, $columns) !== []) {
            throw new ImportConfigurationException(static::class.' uniqueBy columns must be a non-empty subset of columns().');
        }

        if ($targetUniqueBy === []
            || ! array_is_list($targetUniqueBy)
            || collect($targetUniqueBy)->contains(
                static fn (mixed $column): bool => ! is_string($column) || trim($column) === '',
            )) {
            throw new ImportConfigurationException(static::class.' targetUniqueBy columns must be non-empty strings.');
        }

        if ($this->mode() === ImportMode::ATOMIC && $this->atomicCommitter() === null) {
            throw new ImportConfigurationException(static::class.' uses ATOMIC mode but provides no AtomicCommitter.');
        }

        if (trim($this->version()) === '' || strlen($this->version()) > 100) {
            throw new ImportConfigurationException(static::class.' version must be a non-empty string of at most 100 bytes.');
        }

        if ($this->mode() === ImportMode::PARTIAL
            && $this->duplicateStrategy() !== DuplicateStrategy::UPSERT
            && $this->target() === null) {
            throw new ImportConfigurationException(
                static::class.' must provide target() for automatic ERROR, SKIP, or UPDATE duplicate semantics.',
            );
        }

        $referenceNames = [];
        foreach ($this->references() as $reference) {
            if (! $reference instanceof Reference
                || trim($reference->name) === ''
                || trim($reference->targetColumn) === ''
                || trim($reference->valueColumn) === ''
                || ! in_array($reference->sourceColumn, $columns, true)
                || ! is_subclass_of($reference->model, Model::class)
                || isset($referenceNames[$reference->name])) {
                throw new ImportConfigurationException(
                    static::class.' references must have unique names, valid source columns, and Eloquent model classes.',
                );
            }
            $referenceNames[$reference->name] = true;
        }
    }
}
