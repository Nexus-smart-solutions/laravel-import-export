<?php

namespace Nexus\ImportExport\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\Auth\GuestPrincipal;
use Nexus\ImportExport\Contracts\NotificationRecipientResolver;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\Import;

final class CreatorRecipientResolver implements NotificationRecipientResolver
{
    public function resolve(Import|Export $operation): ?object
    {
        if ($operation->actor_type === GuestPrincipal::MORPH_TYPE) {
            $guest = app(GuestAccess::class)->tokens()->where('id', $operation->actor_id)->where('context_hash', $operation->context_hash)
                ->whereNull('revoked_at')->whereNotNull('recipient_verified_at')->whereNotNull('recipient_email')->first();

            return $guest === null ? null : (new AnonymousNotifiable)->route('mail', Crypt::decryptString($guest->recipient_email));
        }
        $class = Relation::getMorphedModel((string) $operation->actor_type) ?? $operation->actor_type;
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }
        $creator = (new $class)->newQuery()->find($operation->actor_id);

        return $creator !== null && method_exists($creator, 'routeNotificationFor') ? $creator : null;
    }
}
