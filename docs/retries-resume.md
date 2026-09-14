# Retry and resume

Import retry preserves completed chunks and requeues failed work where retained files and state allow it. Preparation-level failures may restart source preparation; processing receipts protect already committed business data. Cancellation is a separate terminal outcome, not an implicit retry request. Use the authorized manager/controller entry points.

Exports retain keyset cursors, immutable parts and revisions. The queue payload identifies the export and expected revision; redelivery of a committed revision is a no-op. Failed assembly replays existing parts. Retry checks definition version, cancellation and retention; it does not promise a snapshot of mutable source rows.

Recovery commands requeue stale work after leases/grace periods. Set queue retry_after greater than job timeout and leases safely beyond worker timeout. The separate-process tests gate two PHP workers into competition, then redeliver after both complete, checking business row and progress counts. These tests use SQLite, not a Redis daemon or native MySQL/PostgreSQL server.
