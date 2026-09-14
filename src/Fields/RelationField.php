<?php

namespace Nexus\ImportExport\Fields;

use Closure;
use Nexus\ImportExport\Enums\RelationMissingStrategy;
use Nexus\ImportExport\Exceptions\RejectRow;

final class RelationField extends Field
{
    public string $relationName;

    public string $relatedModel;

    public string $lookupColumn = 'code';

    public string $storedColumn = 'id';

    public string $displayColumn = 'name';

    public ?string $labelColumn = null;

    public string $labelSeparator = ' - ';

    public bool $showDropdown = false;

    public bool $shared = false;

    public ?Closure $queryScope = null;

    public RelationMissingStrategy $missingStrategy = RelationMissingStrategy::ERROR;

    public function belongsTo(string $relation, string $model): self
    {
        $this->type = 'relation';
        $this->relationName = $relation;
        $this->relatedModel = $model;

        return $this;
    }

    public function importBy(string $column): self
    {
        $this->lookupColumn = $column;

        return $this;
    }

    public function storeUsing(string $column): self
    {
        $this->storedColumn = $column;

        return $this;
    }

    public function exportUsing(string $column): self
    {
        $this->displayColumn = $column;

        return $this;
    }

    public function templateUsing(string $column): self
    {
        return $this->importBy($column);
    }

    public function codeAndLabel(string $column = 'name', string $separator = ' - '): self
    {
        $this->labelColumn = $column;
        $this->labelSeparator = $separator;

        return $this;
    }

    public function dropdown(bool $value = true): self
    {
        $this->showDropdown = $value;

        return $this;
    }

    public function sharedAcrossTenants(bool $value = true): self
    {
        $this->shared = $value;

        return $this;
    }

    public function scope(Closure $scope): self
    {
        $this->queryScope = $scope;

        return $this;
    }

    public function missingRelationStrategy(RelationMissingStrategy $strategy): self
    {
        $this->missingStrategy = $strategy;

        return $this;
    }

    public function normalize(mixed $value, array $context): mixed
    {
        $value = parent::normalize($value, $context);
        if ($value !== null && $this->labelColumn !== null && str_contains((string) $value, $this->labelSeparator)) {
            $value = trim(explode($this->labelSeparator, (string) $value, 2)[0]);
        }

        return $value;
    }

    public function templateValue(object $record): string
    {
        $code = (string) $record->{$this->lookupColumn};
        if ($this->labelColumn !== null) {
            if (str_contains($code, $this->labelSeparator)) {
                throw new RejectRow('ambiguous_relation_code', 'A lookup code contains the configured display separator.', $this->name);
            }

            return $code.$this->labelSeparator.$record->{$this->labelColumn};
        }

        return $code;
    }
}
