# Configuration reference

Two published config files retain the original import configuration and add shared metadata/export features.

| File/key | Purpose/default |
| --- | --- |
| bulk-imports.definitions | Trusted resource → definition map |
| bulk-imports.chunk_size / lookup_batch_size / write_batch_size | 1000 / 500 / 1000; DB parameter budgets can lower batches |
| bulk-imports.queue | Queue, async guard, timeouts, tries, backoff and leases |
| bulk-imports.files | Shared private disk, directory, extensions/MIME, retention |
| bulk-imports.limits | Upload/record/row/cell/ZIP bounds |
| bulk-imports.failures | Redaction, buffered writes, report/record retention |
| bulk-imports.database | Active metadata connection and legacy table names |
| bulk-imports.context / contracts | Allowed context keys, restorer and authorizer |
| import-export.current_context | EmptyCurrentContext by default; optional trusted tenant integration |
| import-export.authentication_guard | Null uses host default guard |
| import-export.guests | Disabled, TTL 86400s, retained 7 days after expiry |
| import-export.notifications | Disabled; database creator/mail guest channels, notifications queue, resolver/class/timeout/lease/retry settings |
| import-export.templates | Dropdown 5000, total cells 20000, samples 100, validation rows 10000 |
| import-export.exports | Query pages 2000, selected IDs 1000, retention 7 days, exports queue |
| import-export.csv / xlsx | Delimiter/BOM/line ending/formula safety; sheet row cap |
| import-export.routes | Disabled by default; api/data prefix and api middleware plus mandatory package checks |

Published files are the complete source of operational defaults. Refresh host configuration/cache after changes and restart long-lived workers. Do not use sync queues in production merely to simplify a test integration.
