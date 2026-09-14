<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\Auth\GuestPrincipal;
use Nexus\ImportExport\Contracts\CurrentContext;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Support\CanonicalJson;

final class DataAccess
{
    public function context(): array
    {
        return app(ContextNormalizer::class)->normalize(app(CurrentContext::class)->get());
    }

    public function contextHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->context()));
    }

    public function assertActor(DataDefinition $definition, ?object $actor, string $resource): void
    {
        $this->identity($actor);
        if ($actor instanceof GuestPrincipal) {
            $this->check($definition->allowsGuests() && $actor->resource === $resource && app(GuestAccess::class)->valid($actor));
        }
    }

    public function definition(string $resource, ?array $context = null): DataDefinition
    {
        $classes = config('bulk-imports.definitions', []);
        abort_unless(isset($classes[$resource]) && is_subclass_of($classes[$resource], DataDefinition::class), 404, 'Unknown data resource.');
        $definition = app($classes[$resource]);
        $definition->inContext($context ?? $this->context());
        $definition->validateConfiguration();

        return $definition;
    }

    public function check(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    public function identity(?object $actor): array
    {
        $this->check($actor !== null && method_exists($actor, 'getKey') && $actor->getKey() !== null);

        return [method_exists($actor, 'getMorphClass') ? $actor->getMorphClass() : $actor::class, (string) $actor->getKey()];
    }

    public function authorizeExport(Export $export, ?object $actor): DataDefinition
    {
        [$type, $id] = $this->identity($actor);
        $this->check(hash_equals($export->actor_type, $type) && hash_equals($export->actor_id, $id)
            && hash_equals($export->context_hash, hash('sha256', CanonicalJson::encode($this->context()))));
        $definition = $this->definition($export->resource);
        $this->assertActor($definition, $actor, $export->resource);
        $this->check($definition->canDownloadExport($actor));
        foreach ($export->request['fields'] as $name) {
            $field = $definition->fieldMap()[$name] ?? null;
            $this->check($field !== null && $definition->canExportField($actor, $field));
        }

        return $definition;
    }
}
