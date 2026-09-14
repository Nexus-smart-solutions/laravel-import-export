<?php

namespace Nexus\ImportExport\Data;

final readonly class ImportContext
{
    /**
     * @param  array<string, scalar|null>  $context
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $importId,
        public ?string $actorType,
        public ?string $actorId,
        public array $context,
        public array $options,
    ) {}

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
