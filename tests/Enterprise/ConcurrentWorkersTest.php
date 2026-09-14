<?php

namespace Nexus\ImportExport\Tests\Enterprise;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Exports\ExportRunner;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Jobs\PrepareImportJob;
use Symfony\Component\Process\Process;

final class ConcurrentWorkersTest extends EnterpriseTestCase
{
    private function compete(string $kind, array $ids, int $revision, callable $verify): void
    {
        $path = tempnam(sys_get_temp_dir(), 'engine-workers-');
        unlink($path);
        DB::statement('VACUUM INTO ?', [$path]);
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=10000');
        $gate = $path.'.go';
        $ready = [$path.'.one', $path.'.two'];
        $command = [PHP_BINARY, '-c', php_ini_loaded_file(), 'vendor/bin/phpunit', 'tests/Support/ConcurrentWorker.php', '--no-progress'];
        $base = ['ENGINE_WORKER_DATABASE' => $path, 'ENGINE_WORKER_STORAGE' => Storage::disk('imports-test')->path(''), 'ENGINE_WORKER_KIND' => $kind, 'ENGINE_WORKER_REVISION' => (string) $revision, 'ENGINE_WORKER_GATE' => $gate];
        $processes = [];
        try {
            foreach ($ids as $i => $id) {
                $processes[] = new Process($command, dirname(__DIR__, 2), $base + ['ENGINE_WORKER_ID' => $id, 'ENGINE_WORKER_READY' => $ready[$i]]);
            }
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 20;
            while (! file_exists($ready[0]) || ! file_exists($ready[1])) {
                if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    self::fail('Worker bootstrap failed: '.$processes[0]->getOutput().$processes[0]->getErrorOutput().$processes[1]->getOutput().$processes[1]->getErrorOutput());
                }
                usleep(10000);
            }
            touch($gate);
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            }
            $verify($pdo);
            foreach (array_unique($ids) as $id) {
                $again = new Process($command, dirname(__DIR__, 2), $base + ['ENGINE_WORKER_ID' => $id, 'ENGINE_WORKER_READY' => $ready[0]]);
                $again->mustRun();
            }
            $verify($pdo);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } $pdo = null;
            foreach ([$path, $path.'-wal', $path.'-shm', $gate, ...$ready] as $file) {
                @unlink($file);
            }
        }
    }

    public function test_independent_import_workers_commit_same_chunk_once_then_redelivery_is_noop(): void
    {
        Queue::fake();
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001'], ['B', 'B', 'active', 'SCH001']]), $this->createActor());
        $this->app->call([new PrepareImportJob($import->id, []), 'handle']);
        $chunk = $import->chunks()->sole();
        $this->compete('import', [$chunk->id, $chunk->id], 0, function (\PDO $db): void {
            self::assertSame(2, (int) $db->query('select count(*) from students')->fetchColumn());
            self::assertSame(2, (int) $db->query('select processed_rows from imports')->fetchColumn());
            self::assertSame(1, (int) $db->query('select processed_chunks from imports')->fetchColumn());
        });
    }

    public function test_independent_export_workers_commit_same_revision_once_then_redelivery_is_noop(): void
    {
        $actor = $this->createActor();
        app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001'], ['B', 'B', 'active', 'SCH001']]), $actor);
        Queue::fake();
        $export = app(ExportManager::class)->dispatch('students', $actor);
        app(ExportRunner::class)->step($export->id, 0);
        $export->refresh();
        $this->compete('export', [$export->id, $export->id], $export->revision, function (\PDO $db): void {
            self::assertSame(1, (int) $db->query('select count(*) from data_export_parts')->fetchColumn());
            self::assertSame(2, (int) $db->query('select processed_rows from data_exports')->fetchColumn());
            self::assertSame(2, (int) $db->query('select revision from data_exports')->fetchColumn());
        });
    }

    public function test_independent_distinct_chunks_increment_shared_progress_once_each(): void
    {
        Queue::fake();
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001'], ['B', 'B', 'active', 'SCH001']]), $this->createActor(), ['chunk_size' => 1]);
        $this->app->call([new PrepareImportJob($import->id, []), 'handle']);
        $ids = $import->chunks()->orderBy('chunk_number')->pluck('id')->all();
        $this->compete('import', $ids, 0, function (\PDO $db): void {
            self::assertSame(2, (int) $db->query('select count(*) from students')->fetchColumn());
            self::assertSame(2, (int) $db->query('select processed_rows from imports')->fetchColumn());
            self::assertSame(2, (int) $db->query('select processed_chunks from imports')->fetchColumn());
        });
    }
}
