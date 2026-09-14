# Large exports

Keyset queries load at most export query_chunk_size records (default 2000) and their declared relation values. Each page is released after an immutable part and cursor/progress commit. The final file writer streams parts. Retrying a page never appends to a shared workbook; retrying assembly replays committed parts without rerunning completed business queries.

XLSX automatically splits sheets at the configured rows_per_sheet bound, capped by Excel's 1,048,576 rows including repeated headers. All data sheets use compatible schema/headers for re-import. CSV has no worksheet row limit and is usually preferable for multi-million-row exchange, though consumers such as Excel may impose their own display limits.

Budget disk space for part files and final output. Set assembly timeout/lease and queue retry_after consistently. Data consistency is best-effort with a primary-key high-water bound, not a multi-hour transaction snapshot. See [performance evidence](performance.md) and [production](production.md).
