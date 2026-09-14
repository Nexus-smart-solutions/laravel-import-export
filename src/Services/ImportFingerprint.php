<?php

namespace Nexus\ImportExport\Services;

use Nexus\ImportExport\Support\CanonicalJson;

final class ImportFingerprint
{
    /** @param array<string, scalar|null> $context @param array<string, mixed> $options */
    public function make(
        string $fileHash,
        string $definition,
        string $definitionVersion,
        array $context,
        array $options,
        ?string $actorType = null,
        ?string $actorId = null,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'file_hash' => $fileHash,
            'definition' => $definition,
            'definition_version' => $definitionVersion,
            'context' => $context,
            'options' => config('bulk-imports.idempotency.include_options', true) ? $options : [],
            'actor' => config('bulk-imports.idempotency.include_actor', true)
                ? ['type' => $actorType, 'id' => $actorId]
                : null,
        ]));
    }
}
