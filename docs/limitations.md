# Scope and deployment limits

- Generic belongsToMany pivot synchronization, relation CREATE and true parent/child multi-sheet imports are not implemented. Child foreign-key imports use belongsTo; same-schema split XLSX sheets do not imply child-sheet orchestration.
- DataDefinition uses partial transactions. Legacy atomic staging publishes in a whole-import transaction and should remain bounded or be replaced by a domain-specific publication strategy for large atomic workloads.
- Exports provide a best-effort high-water view, not a strict snapshot. Sort columns must remain non-null and immutable. Arbitrary joins/grouping/offsets are restricted.
- withData outputs import-compatible values via the export engine; it does not append empty-template dropdown/instructions sheets.
- A preparation crash can require re-reading the source before chunk processing. Final output assembly retry replays durable parts. These are bounded-memory operations, not instant operations.
- Notification transport is at-least-once around provider acceptance/receipt crashes. The after-commit event/receipt boundary is not a fully transactional outbox. A verified host reconciliation policy handles that rare gap. Notice failure does not fail a successful business operation.
- Guest contact enrollment/recovery is application-owned. Guest tokens authorize operations belonging to the guest, not arbitrary access to private business resources; opt-in public queries must be scoped intentionally.
- Benchmarks measure the real pipeline with SQLite/local disk and in-process job execution. Separate-process tests verify SQLite receipt/revision competition. Native MySQL/PostgreSQL, Redis/Horizon, S3 and host tenancy/policy integrations require deployment validation. No unexecuted native-driver result is claimed.
