<?php

namespace Nexus\ImportExport\Auth;

/** Stable server-owned identity for CLI/scheduled operations; never resolved from HTTP input. */
final readonly class SystemPrincipal
{
    public const MORPH_TYPE = 'bulk-import-system';

    public function __construct(private string $name)
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $name)) {
            throw new \InvalidArgumentException('Use a stable system identity of 1–100 identifier characters.');
        }
    }

    public function getKey(): string
    {
        return $this->name;
    }

    public function getMorphClass(): string
    {
        return self::MORPH_TYPE;
    }

    public function can(string $ability, mixed $arguments = []): bool
    {
        return false;
    }
}
