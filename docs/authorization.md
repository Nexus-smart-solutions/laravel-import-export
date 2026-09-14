# Ownership and optional authentication

All operation access is enforced in backend middleware/services and database queries. Frontend filtering is not the authorization boundary.

| Caller | Default behavior |
| --- | --- |
| Authenticated creator | Own imports/exports in the active server context |
| Another user | Cannot list/view/control/download that operation |
| Anonymous without a credential | HTTP 401 on protected data routes |
| Valid guest bearer token | Own operations, in one resource and server context |
| Another guest | HTTP 403 for another guest's operation |
| Logged-in user with guest header | Authenticated identity wins |
| Expired or revoked guest token | HTTP 401; direct manager access also denied |
| System/CLI identity | Own operations through the PHP managers; not an HTTP login mechanism |

Enable `import-export.routes.enabled`. The route group uses `api` middleware plus mandatory package authentication and client-identity rejection. Set `authentication_guard` to your host guard (for example `api` for a configured Passport guard). Adding host `auth` middleware will also reject guests before the package can resolve them.

A definition exposes `canImport`, `canExport`, `canDownloadTemplate`, `canExportField`, `canDownloadExport`, and `allowsGuests`. Defaults allow identified actors and explicitly opted-in guests; applications can apply their own policies. Export download rechecks creator/context, definition and selected-field permissions, completion and expiry. No arbitrary filesystem path is accepted or exposed.

Both import and export lists filter owner type/ID and context hash in SQL. ImportAuthorizer is replaceable for intentional delegated/admin access; custom implementations must maintain the required scope. Jobs restore captured context before metadata lookup and do not require a live web session. Revalidating changed business permissions during processing is a host policy; download permissions are checked again.

The new API rejects client `actor_id`, `actor_type`, `user_id`, `tenant_id`, `workspace_id`, `context`, and notification-recipient fields, including corresponding options. Identifiers are obtained from the authenticated actor, guest credential, or server CurrentContext. Reserved top-level email does not prevent an Email spreadsheet column.

See [guests](guest-access.md), [system operations](system-operations.md), [tenancy](multi-tenancy.md), and [notifications](notifications.md).
