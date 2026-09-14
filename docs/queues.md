# Queues and recovery

Use Laravel Queue and Bus Batches. Imports have preparation, chunk, finalization and error-report jobs; exports have revision-based ProcessExportJob deliveries. Optional CompletionNoticeJob uses its own notifications queue. Jobs carry identifiers/context, never spreadsheet row collections.

```bash
php artisan queue:work --queue=imports --timeout=960
php artisan queue:work --queue=exports --timeout=3660
php artisan queue:work --queue=notifications --timeout=90
php artisan bulk-imports:recover-stale
php artisan import-export:maintain --recover
php artisan import-export:retry-notifications --limit=100
```

Use the actual command signatures/configuration and tune worker timeout consistently with each job timeout, leases and connection retry_after. Redis/Horizon are optional; database/SQS queues can also work. Every worker needs access to the same private source/part disk and matching definition code. Tenant context restoration must happen before model lookup.

The notification dispatcher catches enqueue/delivery errors at the business-operation boundary. Retry pending notification receipts independently; replaying a notification must not rerun the import/export. Queue delivery is at-least-once; receipts, revisions and constraints protect business effects.
