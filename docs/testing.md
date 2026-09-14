# Tests

```bash
composer install
vendor/bin/phpunit --exclude-group performance --log-junit verification/phpunit.xml
composer analyse
composer format:check
composer validate --strict
```

composer test excludes the existing performance group; a plain phpunit invocation includes its generated 50k import correctness test. The benchmark harness lives outside the default tests directory and is run explicitly in fresh PHP processes. Results include runtime/database/storage, elapsed throughput, PHP peak allocation, query/relation counts, page/chunk counts and queue payload size. No giant fixtures are committed.

OwnershipAndNotificationsTest covers authenticated/guest/system identities, expiry/revocation, context/recipient forgery, disabled/verified/custom notices, failed delivery, after-commit isolation, rollback, lease exclusion and retries. TenantAccessTest exercises shared-table relation/template/export/list isolation. ConcurrentWorkersTest launches independent PHP processes with a start barrier and a shared file-backed SQLite database/spool, then redelivers the jobs. The subprocess harness retries only transient SQLite BUSY/LOCKED exceptions within the configured queue attempt bound, reflecting queue redelivery after a rolled-back transaction; other errors still fail the test. The helper ConcurrentWorker.php is intentionally not a *Test.php file discovered by the ordinary suite; it is explicitly invoked by that test.

Stored logs/JUnit and the final verification report are the authority for actual counts. Native driver/queue/storage integrations require host CI/deployment tests; do not equate separate SQLite workers with a measured Redis/MySQL production environment.
