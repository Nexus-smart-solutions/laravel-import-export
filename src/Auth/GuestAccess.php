<?php

namespace Nexus\ImportExport\Auth;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Services\DataAccess;

final class GuestAccess
{
    public function tokens(): Builder
    {
        return DB::connection(config('bulk-imports.database.connection'))->table('data_guest_tokens');
    }

    /** @return array{token:string,expires_at:string,principal:GuestPrincipal} */
    public function issue(string $resource): array
    {
        $access = app(DataAccess::class);
        $definition = $access->definition($resource);
        $access->check((bool) config('import-export.guests.enabled', false) && $definition->allowsGuests());
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $id = (string) Str::ulid();
        $expires = now()->addSeconds(max(60, min(604800, (int) config('import-export.guests.ttl_seconds', 86400))));
        $contextHash = $access->contextHash();
        $this->tokens()->insert(['id' => $id, 'token_hash' => $hash, 'resource' => $resource, 'context_hash' => $contextHash, 'expires_at' => $expires, 'created_at' => now()]);

        return ['token' => $token, 'expires_at' => $expires->toIso8601String(), 'principal' => new GuestPrincipal($id, $resource, $contextHash, $hash)];
    }

    public function resolve(?string $token): ?GuestPrincipal
    {
        if (! config('import-export.guests.enabled', false) || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $row = $this->tokens()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if ($row === null || ! hash_equals($row->context_hash, app(DataAccess::class)->contextHash())) {
            return null;
        }
        $classes = config('bulk-imports.definitions', []);
        if (! isset($classes[$row->resource]) || ! is_subclass_of($classes[$row->resource], DataDefinition::class)
            || ! app(DataAccess::class)->definition($row->resource)->allowsGuests()) {
            return null;
        }

        return new GuestPrincipal($row->id, $row->resource, $row->context_hash, $row->token_hash);
    }

    public function valid(GuestPrincipal $principal): bool
    {
        return config('import-export.guests.enabled', false) && hash_equals($principal->contextHash, app(DataAccess::class)->contextHash())
            && $this->tokens()->where('id', $principal->id)->where('resource', $principal->resource)->where('context_hash', $principal->contextHash)
                ->where('token_hash', $principal->credentialHash())->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
    }

    public function revoke(GuestPrincipal $principal): void
    {
        app(DataAccess::class)->check($this->valid($principal));
        $this->tokens()->where('id', $principal->id)->update(['revoked_at' => now(), 'recipient_email' => null, 'recipient_verified_at' => null]);
    }

    /** Server-only: call AFTER the application verifies ownership of the address. No public enrollment endpoint. */
    public function setVerifiedRecipient(GuestPrincipal $principal, string $email): void
    {
        app(DataAccess::class)->assertActor(app(DataAccess::class)->definition($principal->resource), $principal, $principal->resource);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => 'A verified email address is required.']);
        }
        $this->tokens()->where('id', $principal->id)->update(['recipient_email' => Crypt::encryptString($email), 'recipient_verified_at' => now()]);
    }
}
