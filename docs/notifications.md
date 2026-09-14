# Optional notifications

Notifications are disabled by default. Enable/configure them in `import-export.notifications`:

```php
'notifications' => [
    'enabled' => true,
    'channels' => ['database'],
    'guest_channels' => ['mail'],
    'queue' => 'notifications',
    'connection' => null,
    'recipient_resolver' => \Nexus\ImportExport\Notifications\CreatorRecipientResolver::class,
    'notification_class' => \Nexus\ImportExport\Notifications\OperationFinished::class,
],
```

For database delivery, install the host Laravel notifications migration (`php artisan make:notifications-table`, then migrate) and use `Illuminate\Notifications\Notifiable` on the creator model. For mail, configure Laravel's mail transport and a usable notification route. Guest email encryption requires the host `APP_KEY`. Do not copy the test fixture's key.

Terminal import/export events schedule a small CompletionNoticeJob. Its payload contains kind, operation ID, status and captured context. The worker restores context before metadata lookup and resolves the stored creator morph type/ID, not the worker's current authenticated user. Default system identities and deleted creators have no recipient.

Guest contacts require server verification. After your application verifies an email address, call:

```php
app(\Nexus\ImportExport\Auth\GuestAccess::class)
    ->setVerifiedRecipient($guestPrincipal, 'verified@example.com');
```

There is no public email-enrollment endpoint. The address is encrypted in storage, associated with the guest/context, and defaults to mail delivery. Revocation removes it. Expired credentials can still receive a completion notice while their retained, verified contact exists; purged guest records cannot. The HTTP API rejects client-selected recipient fields. A trusted `NotificationRecipientResolver` implementation may instead return a Notifiable model or an AnonymousNotifiable with approved routes, including a verified destination for system work.

Extend `OperationFinished` and configure `notification_class` to customize mail/database content; its constructor stays stable. Laravel custom channel classes can be configured in `channels`/`guest_channels`. Default notices contain only operation kind/ID/status and aggregate counts, not row values, context, raw errors, paths or tokens.

## Failure isolation and retries

NotificationDispatcher registers enqueue work after commit when necessary, records a durable receipt, and catches broker, transport, resolver and logging failures at the operation boundary. A notification failure never changes successful import/export business status. The notification job retries independently (default three attempts; configurable timeout/backoff); receipt errors remain inspectable without storing provider message content.

A receipt per operation/status plus a lease prevents normal duplicate sends. A crash after an external provider accepts mail and before sent_at commits can deliver again: external delivery is at-least-once, not exactly-once. Configure channel timeouts below job timeout and queue retry_after above timeout; the receipt lease is at least timeout + 30 seconds.

`php artisan import-export:retry-notifications --limit=100` resubmits pending receipts older than two minutes with no live lease. Run it on the appropriate metadata connection/context. Sent/skipped receipts are retained 30 days by maintenance. Do not replay deliberately older jobs after receipt retention. Stale status jobs do not send. A crash between operation commit and event/receipt creation is not covered by a transactional outbox; trusted reconciliation can call NotificationDispatcher::queue(kind, id, status, capturedContext) for a known terminal operation.

Tests exercise real database notification storage, fake mail routing, customization, creator/guest selection, context restoration, competing leases, failures before/after commit, retry, and rollback suppression.
