# Benchmark tooling and retained evidence

See [performance](../docs/performance.md) for measured results, methodology and small reproduction commands. All JSON files under results are completed earlier runs; corresponding logs contain the assertions. The 1000000-csv.log file is an interrupted run with no completed result and must not be cited as a benchmark.

No large benchmark is part of the normal release verification. The normal suite uses `composer test`, which excludes the separately opt-in performance group. Benchmark scripts live outside that suite.
