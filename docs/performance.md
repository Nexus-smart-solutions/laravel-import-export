# Measured performance

These are the completed measurements already present in the live working tree on 2026-09-06. No benchmark was rerun during finalization. Only completed JSON results with their accompanying logs are reported; the retained 1,000,000-row log contains a start banner and is not a completed measurement.

Environment: PHP 8.4.25, Laravel 12.69.1, SQLite 3.45.1 using an on-disk database with WAL and synchronous FULL, local filesystem. Imports use CSV for every measurement below; the format column is the export format. Jobs execute the actual pipeline in-process, so these results do not measure Redis/Horizon or broker throughput.

| Rows | Export | Import seconds | Import rows/s | Import peak MiB | Export seconds | Export rows/s | Export peak MiB | Evidence |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 10,000 | CSV | 4.707 | 2,124.5 | 40.0 | 0.649 | 15,406.8 | 44.5 | [JSON](../benchmarks/results/10000-csv.json) |
| 10,000 | XLSX | 5.148 | 1,942.5 | 40.0 | 0.924 | 10,822.1 | 44.5 | [JSON](../benchmarks/results/10000-xlsx.json) |
| 50,000 | CSV | 24.135 | 2,071.6 | 40.0 | 3.272 | 15,282.3 | 44.5 | [JSON](../benchmarks/results/50000-csv.json) |
| 100,000 | CSV | 48.505 | 2,061.6 | 40.0 | 6.564 | 15,234.0 | 44.5 | [JSON](../benchmarks/results/100000-csv.json) |
| 500,000 | CSV | 258.688 | 1,932.8 | 42.0 | 33.430 | 14,956.7 | 44.5 | [JSON](../benchmarks/results/500000-csv.json) |

The harness generates ten Student fields with three belongsTo relations. Import chunks contain 1,000 rows and export pages contain 2,000 rows. PHP peak allocated memory is measured with memory_get_peak_usage(true), not operating-system RSS; export peak is the process peak after the import phase. The completed runs peak at 40–42 MiB for imports and 44.5 MiB after exports. These are observations for this row width and relation cardinality, not a universal memory ceiling.

The 500,000-row run used 500 import chunks and 250 export parts. It recorded 1,500 import relation queries and 750 export relation queries: three per chunk/page, rather than three per row. Total queries were 27,535 for import and 3,283 for export, including operation metadata and checkpoints. The serialized export job was 420 bytes in this fixture. Raw JSON also records database time, chunk duration, source/output sizes and generation time. Metadata and business indexes, relation cardinality, custom validation, row width, worker concurrency and storage latency change these results.

The normal regression suite separately checks 5,000 rows with three relations and exactly three lookup queries for import and three for export. It checks reduced sheet limits to exercise XLSX splitting and re-import without creating million-row fixtures. Independent PHP worker tests verify duplicate delivery/checkpoint concurrency using SQLite; they do not establish native MySQL/PostgreSQL locking behavior.

## Reproduce a small measurement

```bash
composer install
php benchmarks/run.php 1000 csv
# Optional small XLSX export check:
php benchmarks/run.php 1000 xlsx
```

The harness lives in benchmarks/run.php and benchmarks/EngineBenchmarkTest.php. It generates temporary inputs, processes real managers/jobs, asserts committed counters and bounded relation query counts, and writes JSON only after completion. It cleans temporary business data/files afterward. Each invocation uses a fresh PHP process and an isolated temporary database. No giant fixture is committed. Large runs are optional manual deployment work and are not part of the normal release checks.

Native MySQL/PostgreSQL, remote object storage, real queue-broker throughput and host-specific tenant policies were not benchmarked here. Historical conversation-only million-row numbers are not substituted for missing completed artifacts. The architecture supports bounded chunks/pages, but this release's completed measured maximum is 500,000 rows.
