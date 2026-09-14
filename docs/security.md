# Security boundaries

Resource classes and exported fields are whitelisted. Creator/context checks protect lists, status, failures, control endpoints and downloads; guest credentials are expiring/revocable hashes, and identity/tenant/recipient request fields cannot override server state. Guest contacts are encrypted after server verification. Prefer host policies and least-privilege public resource queries.

Untrusted uploads receive size, type, record, header and ZIP/ratio bounds. XML/workbook formulas are not executed. CSV exports/reports neutralize formula-like values; XLSX writers emit strings explicitly. Relation lookups/dropdowns are bounded and context-scoped. Keep private storage, retention, quota, DB unique indexes and row redaction configured.

Custom callbacks, notification routes and tenant restoration are trusted host code. They must not fetch unbounded data, perform per-row SQL or log spreadsheet secrets. External transport errors are separated from business status, and notice payloads exclude row values/paths/tokens. See authorization.md, guest-access.md, notifications.md and limitations.md.
