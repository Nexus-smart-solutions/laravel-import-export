<?php

namespace Nexus\ImportExport\Data;

final readonly class ResolvedReferences
{
    /** @param array<string, array<string, mixed>> $values */
    public function __construct(private array $values = [], private array $ambiguous = []) {}

    public function get(string $reference, string|int|null $sourceValue, mixed $default = null): mixed
    {
        if ($sourceValue === null) {
            return $default;
        }

        return $this->values[$reference][(string) $sourceValue] ?? $default;
    }

    public function isAmbiguous(string $reference, string|int $value): bool
    {
        return isset($this->ambiguous[$reference][(string) $value]);
    }

    public function has(string $reference, string|int|null $sourceValue): bool
    {
        return $sourceValue !== null
            && array_key_exists((string) $sourceValue, $this->values[$reference] ?? []);
    }

    /** @return array<string, mixed> */
    public function all(string $reference): array
    {
        return $this->values[$reference] ?? [];
    }
}
