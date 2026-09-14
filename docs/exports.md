# Asynchronous exports

ExportManager::dispatch(resource, actor, request=[]) accepts csv/xlsx, declared fields, whitelisted filters, bounded selected ids, declared immutable sort/direction and compatible mode. Raw SQL and arbitrary database attributes are not accepted. A definition's trusted query() supplies additional scopes/subqueries; joins/grouping/offset pagination are restricted by ExportQuery.

The runner records an initial primary-key high-water mark and row estimate, then queries bounded keyset pages. Each page is formatted with batched relation values and written as an immutable part. A database transaction publishes its part metadata, next cursor, processed count and revision. Duplicate queue deliveries of a committed revision do nothing. Final assembly streams committed parts into CSV/XLSX and publishes a private output file.

Only the creator in the active context may poll/control/download by default. Downloads require completion, unexpired retention and current field/definition permission. No arbitrary storage path or row/model collection enters the job payload.

A high-water mark does not provide a strict snapshot. Concurrent updates/deletes can affect output. Sort fields must remain non-null and immutable; the primary key breaks ties. Import-compatible exports use accepted input values and required/key fields for safe edits. Use query() preloaded aggregates and computed callbacks without per-row SQL.
