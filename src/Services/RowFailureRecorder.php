<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Nexus\ImportExport\Data\RowFailure;
use Nexus\ImportExport\Enums\FailureType;
use Nexus\ImportExport\Models\ImportFailure;

final class RowFailureRecorder
{
    public function __construct(
        private readonly FailureRedactor $redactor,
        private readonly DatabaseManager $database,
    ) {}

    /** @param list<RowFailure> $failures */
    public function record(string $importId, string $chunkId, array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $now = now();
        $records = [];

        foreach ($failures as $failure) {
            $redacted = $this->redactor->redact($failure->row);
            $records[] = [
                'id' => (string) Str::ulid(),
                'import_id' => $importId,
                'import_chunk_id' => $chunkId,
                'row_number' => $failure->rowNumber,
                'sheet' => $failure->sheet, 'sheet_row' => $failure->sheetRow,
                'failure_type' => FailureType::forCode($failure->code)->value,
                'normalized_row_data' => $failure->normalized === null ? null : json_encode($this->redactor->redact($failure->normalized)['row'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'column' => $failure->column === null ? null : Str::limit($failure->column, 255, ''),
                'error_code' => Str::limit($failure->code, 100, ''),
                'error_message' => Str::limit($failure->message, 4000),
                'original_row_data' => $redacted['row'] === null
                    ? null
                    : json_encode($redacted['row'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'redacted' => $redacted['redacted'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $connection = $this->database->connection(config('bulk-imports.database.connection'));
        $budget = match ($connection->getDriverName()) {
            'sqlite' => 900,
            'sqlsrv' => 2000,
            default => 60000,
        };
        $batchSize = max(1, min(
            (int) config('bulk-imports.failures.write_batch_size', 250),
            intdiv($budget, count($records[0])),
        ));

        foreach (array_chunk($records, $batchSize) as $batch) {
            ImportFailure::query()->insert($batch);
        }
    }
}
