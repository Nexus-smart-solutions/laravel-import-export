# Architecture

The package extends the working import engine instead of replacing it. DataDefinition adapts Field/RelationField metadata onto ImportDefinition and is shared by ImportManager, ExportManager, TemplateManager and DataDescription.

Import preparation reads CSV/XLSX sequentially into bounded, checksummed JSONL chunks. Bus batches dispatch chunk IDs/context. A chunk resolves references in batches, validates, claims keys, bulk-writes and atomically records failures/receipt/progress. Existing retry/cancel/recovery/finalization services remain responsible for import lifecycle.

ExportRunner claims a revision with a lease, reads a bounded keyset page, writes an immutable part and atomically advances part/cursor/counters/revision. Final assembly streams parts into a safe CSV/OpenSpout writer. XLSX sheet splitting is performed while streaming. TemplateWriter uses the same field metadata with bounded option/reference data and a small OOXML validation edit.

DataAccess/ActorImportAuthorizer enforce creator and optional server context. HTTP middleware resolves an authenticated user or valid guest credential and rejects client-selected identity/context/recipient fields. SystemPrincipal remains a server API identity. Notifications run through an isolated after-commit dispatcher, durable receipts and a separate context-aware delivery job.

No service requires a full spreadsheet collection, a model per total exported record, one row per queue job, or a giant row payload. User-provided definitions/callbacks must preserve those bounds. See database-schema.md, performance.md and limitations.md for evidence and boundaries.
