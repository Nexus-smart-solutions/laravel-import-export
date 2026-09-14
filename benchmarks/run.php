<?php

use PHPUnit\TextUI\Application;

// Run each case in a fresh PHP process; datasets are generated at runtime.
$rows = filter_var($argv[1] ?? '10000', FILTER_VALIDATE_INT);
$format = $argv[2] ?? 'csv';
if ($rows === false || $rows < 1 || $rows > 10000000 || ! in_array($format, ['csv', 'xlsx'], true)) {
    fwrite(STDERR, "Usage: php benchmarks/run.php ROWS [csv|xlsx]\n");
    exit(2);
}
putenv('ENGINE_BENCH_ROWS='.$rows);
putenv('ENGINE_BENCH_FORMAT='.$format);
// Isolates Storage::fake from ordinary tests running in other PHP processes.
putenv('TEST_TOKEN=benchmark_'.getmypid());
require dirname(__DIR__).'/vendor/autoload.php';
exit((new Application)->run([__FILE__, __DIR__.'/EngineBenchmarkTest.php', '--no-progress']));
