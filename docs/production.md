# Production integration

Use PHP 8.3+, Laravel 12, indexed business keys/foreign keys, private shared storage and supervised queue workers. Redis/Horizon are useful but optional. Configure auth guard/policies before enabling routes; opt guest resources in deliberately, and apply HTTPS, rate limits, quotas and disk budgets. Configure mail/Notifiable/database tables before enabling notices.

Start with 1000-row import chunks and 2000-row export pages, then benchmark representative row width, validation and relation cardinality on the production database. Keep queue visibility/retry_after above job timeouts and leases beyond timeouts. Use separate import/export/notification queues and manage database connection pressure when increasing concurrency. Workers should recycle by max-jobs/max-time as part of normal operations.

Schedule retained-file cleanup, stale job recovery, export maintenance and notification retry on each active tenant metadata connection. Use the `--dry-run` cleanup mode before first deployment. Do not purge active operation prefixes manually. Source, spool, output and error-report space must all fit the retention budget.

For S3-compatible storage install/configure the appropriate Laravel filesystem adapter and ensure every worker can stream the same disk. Use authorization-protected downloads; the package does not expose arbitrary paths. Host native MySQL/PostgreSQL, Redis/Horizon/SQS, S3 and multi-database tenancy behavior need deployment tests. SQLite/local benchmark rates are not production promises.

Legacy atomic import mode has a whole-publication transaction; use a domain-specific bounded staging/publication design for larger all-or-nothing workflows. The metadata engine uses partial chunk transactions.
