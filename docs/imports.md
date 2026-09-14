# Imports

ImportManager::dispatch(resource, UploadedFile, actor, options=[], idempotency=null) performs fast structural checks, stores the private source and schedules preparation. Production queues must be asynchronous. HTTP creation returns 202; rows are not sent in queue payloads.

Preparation streams CSV/XLSX, normalizes/matches headers and writes bounded checksummed JSONL chunks. Queue jobs normalize/validate, batch-resolve relations, check source/database duplicates, bulk-write valid rows and buffer failures. Business rows, failure records, chunk receipt and aggregate progress commit on one database connection. Invalid rows remain reportable in partial mode.

Options include dry_run (boolean), mapping (source header → logical field), and chunk_size within configured bounds. Do not pass client identity/context through options. Retry/cancel controls go through authorization. The legacy ImportDefinition/BulkImportManager API remains available.

DataDefinition uses PARTIAL mode; global atomic publication is not an assumed million-row capability. Review [duplicates](duplicate-handling.md), [resume](retries-resume.md), [progress](progress.md) and [failure reports](failure-reports.md).
