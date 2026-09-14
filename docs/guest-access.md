# Secure guest access

Guests are disabled by default. Enable both settings deliberately:

```php
// config/import-export.php
'guests' => ['enabled' => true, 'ttl_seconds' => 86400, 'retention_days' => 7],

// On your DataDefinition:
public function allowsGuests(): bool { return true; }
```

`POST /api/data/resources/students/guest-token` returns HTTP 201 with `data.token` and `data.expires_at`, using `Cache-Control: private, no-store`. The raw token is returned once. Each issuance creates a separate identity; it is not a way to recover an existing guest's work. The endpoint has Laravel `throttle:10,1` middleware.

Send `Authorization: Bearer <token>` for metadata, templates, import/export creation, lists, status, failures, controls and downloads. Tokens contain 256 random bits and are stored only as SHA-256 hashes. They bind to one resource and a server-derived context hash. Token lifetime defaults to one day and is clamped to 60 seconds–7 days. Password-like credentials never appear in queue payloads; GuestPrincipal explicitly rejects serialization.

`DELETE /api/data/guest-token` revokes the presented guest identity and clears its verified notification contact. An authenticated user cannot use a guest header to switch identity or revoke another guest. Expiry/revocation blocks access, not already queued business work. The maintenance command removes token rows after expiry plus retention. Choose TTL to cover expected processing and download time; application-specific recovery requires a separate verified ownership flow.

```php
$issued = app(\Nexus\ImportExport\Auth\GuestAccess::class)->issue('students');
$guest = $issued['principal'];
$token = $issued['token']; // Return once to the intended guest; do not log it.
$resolved = app(\Nexus\ImportExport\Auth\GuestAccess::class)->resolve($token);
```

A public guest export may expose all rows allowed by that definition's query. Restrict resource permissions/query and fields before opting in; tokens protect ownership of operations, not eligibility to use a public resource. Apply HTTPS, quotas, storage budgets and appropriate host abuse controls. Tenancy is optional; when used, the server context must match at issuance and access.
