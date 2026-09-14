# Troubleshooting

401: configure the correct authenticated guard, or supply a live guest bearer token with both guest switches enabled. Additional host auth middleware may prevent guest access before package middleware runs.

403: verify creator identity, current server context, definition/field policy and guest resource scope. Do not fix it by trusting tenant_id or actor_id from the request.

422: inspect declared field/filter names, required/key headers, ambiguous aliases and reserved identity/context/recipient input. Template headers and logical/database field names are distinct.

Stuck work: verify workers, shared disk access, Bus batch table, timeout/retry_after/lease configuration and code version. Use recovery commands after stale leases expire, not repeated full-file submissions.

No notice: check notifications.enabled, Notifiable model/channel setup, verified guest contact, APP_KEY, queue worker and pending receipt error_code. `import-export:retry-notifications` retries eligible receipts. A successful operation remains successful when notices fail.

Memory/disk pressure: reduce chunks and dropdown limits, inspect custom callbacks for unbounded work, and budget original/spool/part/output retention. Prefer CSV for multi-million-row exchange.
