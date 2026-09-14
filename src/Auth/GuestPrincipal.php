<?php

namespace Nexus\ImportExport\Auth;

final readonly class GuestPrincipal
{
    public const MORPH_TYPE = 'bulk-import-guest';

    public function __construct(public string $id, public string $resource, public string $contextHash, private string $credentialHash) {}

    public function getKey(): string
    {
        return $this->id;
    }

    public function getMorphClass(): string
    {
        return self::MORPH_TYPE;
    }

    public function credentialHash(): string
    {
        return $this->credentialHash;
    }

    public function can(string $ability, mixed $arguments = []): bool
    {
        return false;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Guest credentials must not be serialized into jobs. Use the operation ID.');
    }
}
