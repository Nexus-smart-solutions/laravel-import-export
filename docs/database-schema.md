# Database schema

Ten migration files are automatically loaded and all ten can be published under `import-export-migrations` (with `bulk-imports-migrations` retained as a legacy alias). The six original import tables retain their configurable names. Later migrations add import statistics, exports/parts, failure locations, and guest/notification tables.

| Table | Role and key constraints |
| --- | --- |
| imports | ULID; creator/context/fingerprint, state, counters, lease, file/report/retention metadata |
| import_chunks | Stable import/chunk identity, source range/checksum, state/lease and per-chunk counters |
| import_failures | Bounded field/row diagnostics and original/normalized data |
| import_row_keys | First source-key claim across an import |
| import_staged_rows | Legacy atomic-mode staging |
| import_key_locks | Cross-import business key coordination |
| data_exports | ULID, creator/context, request whitelist, high-water/cursor/revision, counters/lease/retention |
| data_export_parts | Unique export/part identity with immutable path/checksum/row count |
| data_guest_tokens | ULID, unique SHA-256 credential hash, resource/context binding, indexed expiry, revocation and encrypted verified contact |
| data_notification_receipts | Unique kind/operation/status hash, captured context, send lease/attempts, sent/skipped timestamps, safe error_code; recovery index |

Metadata imports and target writes must share one connection for transaction guarantees. Guest/notice tables use the configured bulk-imports metadata connection; tenant workers must initialize it before access. Business foreign keys/unique indexes and Laravel job_batches/notifications tables are host responsibilities. Example business migrations are not auto-installed.
