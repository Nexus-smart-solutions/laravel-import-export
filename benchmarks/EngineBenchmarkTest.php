<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Events\ImportChunkCompleted;
use Nexus\ImportExport\Events\ImportChunkStarted;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\Exports\ExportRunner;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Jobs\ProcessExportJob;
use Nexus\ImportExport\Tests\Enterprise\EnterpriseTestCase;

final class EngineBenchmarkTest extends EnterpriseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $path = dirname(__DIR__).'/build/benchmark-'.getmypid().'.sqlite';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        touch($path);
        $app['config']->set('database.connections.testing.database', $path);
        $app['config']->set('logging.default', 'null');
    }

    public function test_benchmark(): void
    {
        $rows = (int) getenv('ENGINE_BENCH_ROWS');
        $format = getenv('ENGINE_BENCH_FORMAT') ?: 'csv';
        $result = $this->measure($rows, $format);
        self::assertSame($rows, $result['rows']);
        self::assertGreaterThan(0, $result['export_bytes']);
        self::assertSame($result['import_chunks'] * 3, $result['import_relation_queries']);
        self::assertSame($result['export_parts'] * 3, $result['export_relation_queries']);
        file_put_contents(__DIR__.'/results/'.$rows.'-'.$format.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        echo json_encode($result, JSON_UNESCAPED_SLASHES)."\n";
    }

    private function measure(int $rows, string $format): array
    {
        $source = tempnam(sys_get_temp_dir(), 'engine-bench-');
        try {
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA synchronous=FULL');
            DB::disableQueryLog();
            $actor = $this->createActor();
            $queries = $relationQueries = 0;
            $databaseMs = 0.0;
            DB::listen(function ($event) use (&$queries, &$relationQueries, &$databaseMs): void {
                $queries++;
                $databaseMs += $event->time;
                if (str_starts_with(strtolower($event->sql), 'select') && preg_match('/from ["`](schools|classrooms|countries)["`]/', $event->sql)) {
                    $relationQueries++;
                }
            });
            $chunkStart = 0.0;
            $chunkSeconds = 0.0;
            $maxChunkSeconds = 0.0;
            $chunkCount = 0;
            Event::listen(ImportChunkStarted::class, function () use (&$chunkStart): void {
                $chunkStart = microtime(true);
            });
            Event::listen(ImportChunkCompleted::class, function () use (&$chunkStart, &$chunkSeconds, &$maxChunkSeconds, &$chunkCount): void {
                $elapsed = microtime(true) - $chunkStart;
                $chunkSeconds += $elapsed;
                $maxChunkSeconds = max($maxChunkSeconds, $elapsed);
                $chunkCount++;
            });
            $start = microtime(true);
            $stream = fopen($source, 'wb');
            fputcsv($stream, ['Student Code', 'Student Name', 'Email', 'Phone', 'Birth Date', 'Status', 'School', 'Class Code', 'Country Code', 'Active'], ',', '"', '');
            for ($i = 1; $i <= $rows; $i++) {
                fputcsv($stream, [sprintf('S%09d', $i), 'Student '.$i, 'student'.$i.'@example.test', '01000000000', '2000-01-01', 'Active', 'SCH001 - Future Language School', 'CLS01', 'EG', 'Yes'], ',', '"', '');
            }
            fclose($stream);
            $generation = microtime(true) - $start;
            memory_reset_peak_usage();
            $start = microtime(true);
            $import = app(ImportManager::class)->dispatch('students', new UploadedFile($source, 'students.csv', 'text/csv', test: true), $actor);
            $importSeconds = microtime(true) - $start;
            if ($import->status->value !== 'completed' || $import->processed_rows !== $rows || $import->inserted_rows !== $rows) {
                throw new RuntimeException('Benchmark import accounting failed.');
            }
            $result = ['rows' => $rows, 'import_format' => 'csv', 'export_format' => $format, 'php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => 'SQLite '.DB::selectOne('select sqlite_version() as version')->version,
                'storage' => 'local', 'execution' => 'real pipeline / in-process jobs; no broker throughput measured', 'sqlite_journal' => 'WAL / synchronous FULL',
                'import_chunk_size' => config('bulk-imports.chunk_size'), 'export_chunk_size' => config('import-export.exports.query_chunk_size'),
                'source_bytes' => filesize($source), 'source_generation_seconds' => round($generation, 3), 'import_seconds' => round($importSeconds, 3),
                'import_rows_per_second' => round($rows / $importSeconds, 1), 'import_peak_php_bytes' => memory_get_peak_usage(true),
                'import_queries' => $queries, 'import_database_seconds' => round($databaseMs / 1000, 3), 'import_relation_queries' => $relationQueries, 'import_chunks' => $import->total_chunks,
                'mean_chunk_seconds' => round($chunkSeconds / max(1, $chunkCount), 4), 'max_chunk_seconds' => round($maxChunkSeconds, 4)];
            fwrite(STDERR, json_encode(['phase' => 'import', 'rows' => $rows, 'seconds' => $result['import_seconds'], 'peak_php_bytes' => $result['import_peak_php_bytes']])."\n");
            $queries = $relationQueries = 0;
            $databaseMs = 0.0;
            memory_reset_peak_usage();
            Queue::fake([ProcessExportJob::class]);
            $start = microtime(true);
            $export = app(ExportManager::class)->dispatch('students', $actor, ['format' => $format]);
            $jobBytes = strlen(serialize(new ProcessExportJob($export->id, [], $export->revision)));
            $runner = app(ExportRunner::class);
            while (! $export->refresh()->status->terminal()) {
                $runner->step($export->id, $export->revision);
            }
            $seconds = microtime(true) - $start;
            if ($export->status->value !== 'completed' || $export->processed_rows !== $rows) {
                throw new RuntimeException('Benchmark export accounting failed.');
            }

            return $result + ['export_seconds' => round($seconds, 3), 'export_rows_per_second' => round($rows / $seconds, 1), 'export_peak_php_bytes' => memory_get_peak_usage(true),
                'export_queries' => $queries, 'export_database_seconds' => round($databaseMs / 1000, 3), 'export_relation_queries' => $relationQueries, 'export_parts' => $export->parts_count,
                'export_bytes' => Storage::disk($export->disk)->size($export->file_path), 'export_job_serialized_bytes' => $jobBytes, 'date_utc' => gmdate(DATE_ATOM)];
        } finally {
            @unlink($source);
        }
    }

    protected function tearDown(): void
    {
        $database = config('database.connections.testing.database');
        $storage = Storage::disk('imports-test')->path('');
        parent::tearDown();
        foreach ([$database, $database.'-wal', $database.'-shm'] as $path) {
            @unlink($path);
        }
        (new Filesystem)->deleteDirectory($storage);
    }
}
