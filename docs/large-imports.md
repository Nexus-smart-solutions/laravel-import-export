# Large imports

Rows are streamed once into checksummed JSONL spool chunks. Independent workers read their own chunk rather than rescanning the XLSX from its first row. Memory scales with row/chunk width, configured chunk count and relation maps, not the total spreadsheet. Chunk payloads contain IDs and captured context only.

Tune chunk_size (default 1000, maximum option 5000), import-export.chunk_max_bytes (16 MiB), lookup_batch_size and write_batch_size to row width and database parameter/packet limits. Incoming file, CSV-record, XLSX archive/compression and cell limits bound untrusted input. Monitor shared-disk capacity for original file plus spools/failure reports; bounded PHP memory does not imply zero disk growth.

Preparation may re-read the source after a crash before processing begins. Already committed processing chunks resume through durable receipts. Use a normal database unique index for business identity; cross-import key locking and constraints remain necessary. The supplied benchmarks exercise the real pipeline with generated data and SQLite/local disk; deploy-scale throughput requires native database/worker measurements.
