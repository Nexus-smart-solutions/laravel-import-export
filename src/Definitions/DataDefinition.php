<?php

namespace Nexus\ImportExport\Definitions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nexus\ImportExport\Auth\GuestPrincipal;
use Nexus\ImportExport\Contracts\ImportTarget;
use Nexus\ImportExport\Data\ImportContext;
use Nexus\ImportExport\Data\ResolvedReferences;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\RelationMissingStrategy;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Exceptions\RejectRow;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\Fields\RelationField;
use Nexus\ImportExport\Support\Header;

abstract class DataDefinition extends ImportDefinition
{
    private ?array $fieldCache = null;

    protected array $context = [];

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    public function inContext(array $context): static
    {
        $this->context = $context;
        $this->fieldCache = null;
        if ($this->tenantColumn() !== null && ! isset($context[$this->tenantContextKey()])) {
            throw new ImportConfigurationException('A server-derived tenant context is required by this definition.');
        }

        return $this;
    }

    public function worksheet(): ?string
    {
        return '*';
    }

    public function context(): array
    {
        return $this->context;
    }

    public function tenantColumn(): ?string
    {
        return null;
    }

    public function tenantContextKey(): string
    {
        return 'tenant_id';
    }

    public function allowsGuests(): bool
    {
        return false;
    }

    public function canImport(?object $actor): bool
    {
        return $actor !== null && (! $actor instanceof GuestPrincipal || $this->allowsGuests());
    }

    public function canExport(?object $actor): bool
    {
        return $actor !== null && (! $actor instanceof GuestPrincipal || $this->allowsGuests());
    }

    public function canDownloadTemplate(?object $actor): bool
    {
        return $this->canImport($actor);
    }

    public function canExportField(?object $actor, Field $field): bool
    {
        return $this->canExport($actor) && $field->canExport;
    }

    public function canDownloadExport(?object $actor): bool
    {
        return $this->canExport($actor);
    }

    /** Optional, explicit samples keyed by field name, never queried from production. */
    public function templateRows(): array
    {
        return [];
    }

    /** Trusted callbacks: name => function (Builder $query, mixed $value, array $context): void. */
    public function filters(): array
    {
        return [];
    }

    /** name => immutable, non-null database column. Primary key is always the tie-breaker. */
    public function sorts(): array
    {
        return [];
    }

    public function query(): Builder
    {
        return (new ($this->model()))->newQuery();
    }

    public function scopeQuery(Builder $query): Builder
    {
        if (($column = $this->tenantColumn()) !== null) {
            if (! isset($this->context[$this->tenantContextKey()])) {
                throw new ImportConfigurationException('Missing tenant context.');
            }
            $query->where($query->getModel()->qualifyColumn($column), $this->context[$this->tenantContextKey()]);
        }

        return $query;
    }

    public function relationQuery(RelationField $field): Builder
    {
        $query = (new ($field->relatedModel))->newQuery();
        if (! $field->shared) {
            $this->scopeQuery($query);
        }
        if ($field->queryScope !== null) {
            ($field->queryScope)($query, $this->context);
        }

        return $query;
    }

    /** @return array<string,Field> */
    final public function fieldMap(): array
    {
        if ($this->fieldCache !== null) {
            return $this->fieldCache;
        }
        $map = [];
        foreach ($this->fields() as $field) {
            if (! $field instanceof Field || isset($map[$field->name])) {
                throw new ImportConfigurationException('Fields must be Field objects with unique names.');
            }
            $map[$field->name] = $field;
        }

        return $this->fieldCache = $map;
    }

    public function columns(): array
    {
        return array_keys(array_filter($this->fieldMap(), fn (Field $f) => $f->canImport));
    }

    public function uniqueBy(): array
    {
        return array_keys(array_filter($this->fieldMap(), fn (Field $f) => $f->isUnique));
    }

    public function targetUniqueBy(): array
    {
        $map = $this->fieldMap();
        $keys = array_map(fn (string $name) => $map[$name]->databaseColumn, $this->uniqueBy());

        return $this->tenantColumn() === null ? $keys : array_values(array_unique([$this->tenantColumn(), ...$keys]));
    }

    public function requiredColumns(): array
    {
        return array_values(array_unique([...$this->uniqueBy(), ...array_keys(array_filter($this->fieldMap(), fn (Field $f) => $f->canImport && $f->isRequired))]));
    }

    public function headerAliases(): array
    {
        $aliases = [];
        foreach ($this->fieldMap() as $field) {
            if (! $field->canImport) {
                continue;
            }
            foreach ([$field->name, $field->importHeader, $field->templateHeader, ...$field->headerAliases] as $header) {
                $header = Header::normalize($header);
                if ($header === '' || (isset($aliases[$header]) && $aliases[$header] !== $field->name)) {
                    throw new ImportConfigurationException('Ambiguous import header aliases.');
                }
                $aliases[$header] = $field->name;
            }
        }

        return $aliases;
    }

    public function normalize(array $row): array
    {
        foreach ($this->fieldMap() as $name => $field) {
            if ($field->canImport && (array_key_exists($name, $row) || $field->hasDefault)) {
                $row[$name] = $field->normalize($row[$name] ?? null, $this->context);
            }
        }

        return $row;
    }

    public function rules(): array
    {
        $rules = [];
        foreach ($this->fieldMap() as $name => $field) {
            if ($field->canImport) {
                $rules[$name] = $field->validationRules($this->context);
            }
        }

        return $rules;
    }

    public function target(): ?ImportTarget
    {
        $columns = array_map(fn (Field $f) => $f->databaseColumn, array_filter($this->fieldMap(), fn (Field $f) => $f->canImport));

        return EloquentTarget::for($this->model(), array_values(array_diff($columns, $this->targetUniqueBy())));
    }

    public function references(): array
    {
        $refs = [];
        foreach ($this->fieldMap() as $field) {
            if (! $field instanceof RelationField || ! $field->canImport) {
                continue;
            }
            $refs[] = Reference::make($field->name, $field->relatedModel, $field->name, $field->lookupColumn)
                ->value($field->storedColumn)->select([$field->lookupColumn, $field->storedColumn])->optional()
                ->scope(function (Builder $query) use ($field): void {
                    if (! $field->shared) {
                        $this->scopeQuery($query);
                    }
                    if ($field->queryScope !== null) {
                        ($field->queryScope)($query, $this->context);
                    }
                });
        }

        return $refs;
    }

    public function transform(array $row, ResolvedReferences $references, ImportContext $context): array
    {
        $payload = [];
        foreach ($this->fieldMap() as $name => $field) {
            if (! $field->canImport && ! ($field->hasDefault && ! $field->inTemplate)) {
                continue;
            }
            if (! array_key_exists($name, $row) && ! $field->hasDefault) {
                continue;
            }
            $value = $row[$name] ?? $field->defaultValue;
            if ($field instanceof RelationField && $value !== null) {
                if ($references->isAmbiguous($name, $value)) {
                    throw new RejectRow('ambiguous_relation', 'The lookup value is not unique within its scope.', $name);
                }
                if (! $references->has($name, $value)) {
                    if ($field->missingStrategy === RelationMissingStrategy::ERROR) {
                        throw new RejectRow('reference_not_found', 'The related lookup value was not found.', $name);
                    }
                    if ($field->missingStrategy === RelationMissingStrategy::SKIP_ROW) {
                        throw new RejectRow('skip_row', 'The related lookup value was not found.', $name);
                    }
                }
                $value = $references->get($name, $value);
            }
            $payload[$field->databaseColumn] = $value;
        }
        if ($this->tenantColumn() !== null) {
            $payload[$this->tenantColumn()] = $this->context[$this->tenantContextKey()];
        }

        return $payload;
    }

    public function validateConfiguration(): void
    {
        $map = $this->fieldMap();
        if (! is_subclass_of($this->model(), Model::class)) {
            throw new ImportConfigurationException('model() must return an Eloquent model class.');
        }
        if ($this->mode() !== ImportMode::PARTIAL) {
            throw new ImportConfigurationException('DataDefinition supports bounded partial imports; use a domain-specific staging architecture for global atomic publication.');
        }
        if (count($map) > (int) config('bulk-imports.limits.max_columns', 250)) {
            throw new ImportConfigurationException('Too many fields.');
        }
        $columns = [];
        foreach ($map as $field) {
            foreach ([$field->name, $field->databaseColumn] as $identifier) {
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
                    throw new ImportConfigurationException('Field names and database columns must be simple identifiers.');
                }
            }
            if ($field->canImport && isset($columns[$field->databaseColumn])) {
                throw new ImportConfigurationException('Two import fields target the same database column.');
            }
            if ($field->canImport) {
                $columns[$field->databaseColumn] = true;
            }
            if ($field->isRequired && $field->isNullable) {
                throw new ImportConfigurationException('A required field cannot also be nullable.');
            }
            if ($field->databaseColumn === $this->tenantColumn() && ($field->canImport || $field->canExport || $field->inTemplate)) {
                throw new ImportConfigurationException('Tenant attributes must be server-controlled internal fields.');
            }
            if ($field instanceof RelationField) {
                if (! isset($field->relatedModel, $field->relationName) || ! is_subclass_of($field->relatedModel, Model::class)) {
                    throw new ImportConfigurationException('Configure belongsTo on every relation field.');
                }
                if ($field->labelSeparator === '') {
                    throw new ImportConfigurationException('Relation label separator cannot be empty.');
                }
                foreach ([$field->lookupColumn, $field->storedColumn, $field->displayColumn, $field->labelColumn ?? 'name'] as $column) {
                    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) {
                        throw new ImportConfigurationException('Relation columns must be simple identifiers.');
                    }
                }
                $relation = (new ($this->model()))->{$field->relationName}();
                if (! $relation instanceof BelongsTo || $relation->getRelated()::class !== $field->relatedModel
                    || $relation->getForeignKeyName() !== $field->databaseColumn || $relation->getOwnerKeyName() !== $field->storedColumn) {
                    throw new ImportConfigurationException('Relation metadata must match the Eloquent belongsTo relationship.');
                }
                if ($field->missingStrategy === RelationMissingStrategy::NULL && ! $field->isNullable) {
                    throw new ImportConfigurationException('NULL missing-relation behavior requires nullable().');
                }
            }
        }
        $this->headerAliases();
        parent::validateConfiguration();
    }
}
