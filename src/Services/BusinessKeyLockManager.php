<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\DatabaseManager;

final class BusinessKeyLockManager
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Acquire transaction-scoped logical locks. Must be called inside a transaction;
     * release() deletes them before commit. A rollback also removes every lock.
     *
     * @param  list<string>  $keyHashes
     */
    public function acquire(string $connection, string $scope, array $keyHashes, string $importId): void
    {
        $keyHashes = array_values(array_unique($keyHashes));
        sort($keyHashes, SORT_STRING);

        if ($keyHashes === []) {
            return;
        }

        $scopeHash = hash('sha256', $scope);
        $now = now();
        $rows = array_map(static fn (string $hash): array => [
            'scope_hash' => $scopeHash,
            'key_hash' => $hash,
            'owner_import_id' => $importId,
            'created_at' => $now,
        ], $keyHashes);

        $database = $this->database->connection($connection);
        $table = config('bulk-imports.database.tables.key_locks', 'import_key_locks');
        $batchSize = $this->safeBatchSize($database->getDriverName(), 4);

        foreach (array_chunk($rows, $batchSize) as $batch) {
            $database->table($table)->insert($batch);
        }
    }

    /** @param list<string> $keyHashes */
    public function release(string $connection, string $scope, array $keyHashes, string $importId): void
    {
        if ($keyHashes === []) {
            return;
        }

        $database = $this->database->connection($connection);
        $table = config('bulk-imports.database.tables.key_locks', 'import_key_locks');
        $batchSize = $this->safeBatchSize($database->getDriverName(), 1);

        foreach (array_chunk(array_values(array_unique($keyHashes)), $batchSize) as $hashBatch) {
            $database->table($table)
                ->where('scope_hash', hash('sha256', $scope))
                ->where('owner_import_id', $importId)
                ->whereIn('key_hash', $hashBatch)
                ->delete();
        }
    }

    public function releaseAll(string $connection, string $scope, string $importId): void
    {
        $this->database->connection($connection)
            ->table(config('bulk-imports.database.tables.key_locks', 'import_key_locks'))
            ->where('scope_hash', hash('sha256', $scope))
            ->where('owner_import_id', $importId)
            ->delete();
    }

    private function safeBatchSize(string $driver, int $parametersPerRow): int
    {
        $budget = match ($driver) {
            'sqlite' => 900,
            'sqlsrv' => 2000,
            default => 60000,
        };

        return max(1, intdiv($budget, $parametersPerRow));
    }
}
