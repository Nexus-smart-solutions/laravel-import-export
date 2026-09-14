# Progress

ImportResource returns progress counters including totalRows, processedRows, succeededRows, failedRows, skippedRows, insertedRows, updatedRows, wouldInsert, wouldUpdate, processedChunks and percentage, alongside status/chunk information. Counters are cast to integers. Inspect the resource for legacy-compatible naming.

Import counters increment inside the transaction that publishes the chunk receipt and business writes. Redelivery cannot increment them twice. Export responses expose total_rows, processed_rows, percentage, status and download_available; a checkpoint transaction publishes rows/cursor/revision together. Before an export is complete, displayed progress is capped below 100 even when all query pages have been read, because assembly may remain.

Only the owner in the current trusted context sees lists/status by default; possession of an operation ID is not sufficient. Guest credentials provide an owner identity without mandatory tenancy or login.
