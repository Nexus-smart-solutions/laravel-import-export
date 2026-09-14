<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\Auth\GuestPrincipal;
use Nexus\ImportExport\Contracts\ImportAuthorizer;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Support\CanonicalJson;

final class ActorImportAuthorizer implements ImportAuthorizer
{
    public function canCreate(?object $actor, string $type, array $context): bool
    {
        return ! config('bulk-imports.authorization.require_actor', true) || $this->identity($actor) !== null;
    }

    public function canView(?object $actor, Import $import): bool
    {
        $identity = $this->identity($actor);
        if (is_subclass_of($import->definition, DataDefinition::class)) {
            try {
                app(DataAccess::class)->assertActor(app(DataAccess::class)->definition($import->type), $actor, $import->type);
            } catch (AuthorizationException) {
                return false;
            }
            $context = app(DataAccess::class)->context();
            if (! hash_equals($import->context_hash, hash('sha256', CanonicalJson::encode($context)))) {
                return false;
            }
        }

        return $identity !== null
            && hash_equals((string) $import->actor_type, $identity['type'])
            && hash_equals((string) $import->actor_id, $identity['id']);
    }

    public function canCancel(?object $actor, Import $import): bool
    {
        return $this->canView($actor, $import);
    }

    public function canRetry(?object $actor, Import $import): bool
    {
        return $this->canView($actor, $import);
    }

    public function scopeVisible(Builder $query, ?object $actor): Builder
    {
        $identity = $this->identity($actor);
        if ($actor instanceof GuestPrincipal && ! app(GuestAccess::class)->valid($actor)) {
            return $query->whereRaw('1 = 0');
        }

        if ($identity === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('actor_type', $identity['type'])
            ->where('actor_id', $identity['id'])
            ->where('context_hash', app(DataAccess::class)->contextHash());
    }

    /** @return null|array{type:string,id:string} */
    private function identity(?object $actor): ?array
    {
        if ($actor === null || ! method_exists($actor, 'getKey')) {
            return null;
        }

        $type = method_exists($actor, 'getMorphClass') ? $actor->getMorphClass() : $actor::class;

        return ['type' => (string) $type, 'id' => (string) $actor->getKey()];
    }
}
