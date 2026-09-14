<?php

namespace Nexus\ImportExport\Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Nexus\ImportExport\ImportExportServiceProvider;
use Nexus\ImportExport\Examples\Enterprise\Definitions\StudentDefinition;
use Nexus\ImportExport\Jobs\ProcessExportJob;
use Nexus\ImportExport\Jobs\ProcessImportChunkJob;
use Orchestra\Testbench\TestCase;

/** Explicit subprocess harness. It uses the parent's persisted database and shared spool, with no migration/reset. */
final class ConcurrentWorker extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ImportExportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => getenv('ENGINE_WORKER_DATABASE'), 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.disks.imports-test', ['driver' => 'local', 'root' => getenv('ENGINE_WORKER_STORAGE'), 'throw' => true]);
        $app['config']->set('bulk-imports.files.disk', 'imports-test');
        $app['config']->set('bulk-imports.definitions', ['students' => StudentDefinition::class]);
        $app['config']->set('logging.default', 'null');
    }

    public function test_process_one_delivery(): void
    {
        Queue::fake();
        $id = getenv('ENGINE_WORKER_ID');
        $job = getenv('ENGINE_WORKER_KIND') === 'export' ? new ProcessExportJob($id, [], (int) getenv('ENGINE_WORKER_REVISION')) : new ProcessImportChunkJob($id, []);
        // Both parents release their workers only after both have booted.
        file_put_contents(getenv('ENGINE_WORKER_READY'), 'ready');
        $deadline = microtime(true) + 15;
        while (! file_exists(getenv('ENGINE_WORKER_GATE'))) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Worker barrier timed out.');
            } usleep(10000);
        }
        // A real queue redelivers transient lock failures after backoff. SQLite
        // can exhaust the immediate transaction retries during WAL promotion.
        // Keep the retry bound, and never swallow other database failures.
        for ($attempt = 1; $attempt <= $job->tries; $attempt++) {
            try {
                $this->app->call([$job, 'handle']);
                break;
            } catch (QueryException $exception) {
                $sqliteCode = (int) ($exception->errorInfo[1] ?? 0) & 0xFF;
                if (! in_array($sqliteCode, [5, 6], true) || $attempt === $job->tries) {
                    throw $exception;
                }
                usleep(25000 * $attempt);
            }
        }
        self::assertTrue(true);
    }
}
