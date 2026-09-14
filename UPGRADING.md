# Upgrading the final layer

Existing ImportDefinition, DataDefinition, Field/RelationField and manager calls remain available. Run the new guest/notification migration; all migration files are now publishable. Refresh import-export configuration after publishing and restart workers. Business examples/migrations are not automatically installed.

New data routes use mandatory package authentication and identity rejection. routes.middleware defaults to api; specify authentication_guard for a named host guard. Existing host auth middleware can be retained for authenticated-only deployment, but will block guests. Guest access is disabled globally and on definitions by default. Requests must stop sending actor/tenant/context/recipient fields and use trusted server integration instead.

Tenancy remains optional. System/CLI callers can use SystemPrincipal instead of an authenticated user. New counters are integer-cast. Import lists now include context scope; hosts using nonempty legacy context must configure matching CurrentContext.

Notifications are opt-in. Configure Notifiable models, host database-notification migration/mail transport, APP_KEY for verified guest contacts, and the notification worker. Keep queue timeout/retry_after/lease settings consistent. Composer now explicitly declares illuminate/notifications; the lockfile records the existing Laravel framework implementation.
