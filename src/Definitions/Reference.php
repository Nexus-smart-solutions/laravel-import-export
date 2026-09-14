<?php

namespace Nexus\ImportExport\Definitions;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class Reference
{
    /** @var list<string> */
    public array $select = [];

    public string $valueColumn = 'id';

    public bool $required = true;

    public string $errorCode = 'reference_not_found';

    public ?string $errorMessage = null;

    /** @var null|Closure(Builder):void */
    public ?Closure $scope = null;

    private function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly string $sourceColumn,
        public readonly string $targetColumn,
    ) {
        $this->select = [$targetColumn, 'id'];
    }

    public static function make(
        string $name,
        string $model,
        string $sourceColumn,
        string $targetColumn,
    ): self {
        return new self($name, $model, $sourceColumn, $targetColumn);
    }

    /** @param list<string> $columns */
    public function select(array $columns): self
    {
        $this->select = array_values(array_unique([...$columns, $this->targetColumn, $this->valueColumn]));

        return $this;
    }

    public function value(string $column): self
    {
        $this->valueColumn = $column;
        $this->select = array_values(array_unique([...$this->select, $column]));

        return $this;
    }

    public function optional(): self
    {
        $this->required = false;

        return $this;
    }

    public function missing(string $code, string $message): self
    {
        $this->errorCode = $code;
        $this->errorMessage = $message;

        return $this;
    }

    /** @param Closure(Builder):void $callback */
    public function scope(Closure $callback): self
    {
        $this->scope = $callback;

        return $this;
    }
}
