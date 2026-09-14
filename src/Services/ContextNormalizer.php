<?php

namespace Nexus\ImportExport\Services;

use InvalidArgumentException;
use JsonException;
use Nexus\ImportExport\Support\CanonicalJson;

final class ContextNormalizer
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    public function normalize(array $context): array
    {
        $allowed = config('bulk-imports.context.allowed_keys', []);
        $normalized = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Context key [{$key}] is not allowed.");
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Context value [{$key}] must be scalar or null.");
            }
            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException("Context value [{$key}] must be a finite number.");
            }

            $normalized[(string) $key] = $value;
        }

        ksort($normalized);

        try {
            $encoded = CanonicalJson::encode($normalized);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Context must be valid UTF-8 JSON data.', 0, $exception);
        }

        if (strlen($encoded) > (int) config('bulk-imports.context.max_bytes', 4096)) {
            throw new InvalidArgumentException('The serialized import context is too large.');
        }

        return $normalized;
    }
}
