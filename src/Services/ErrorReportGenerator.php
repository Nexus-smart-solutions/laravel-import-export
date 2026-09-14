<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Filesystem\FilesystemManager;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportFailure;
use RuntimeException;

final class ErrorReportGenerator
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    /** @return array{disk:string,path:string} */
    public function generate(Import $import): array
    {
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open an error-report stream.');
        }

        try {
            fputcsv(
                $stream,
                ['Original Row Number', 'Original Data', 'Error Column', 'Error Code', 'Error Message', 'Sheet', 'Sheet Row', 'Failure Type'],
                ',',
                '"',
                '',
                "\n",
            );

            $failures = ImportFailure::query()
                ->where('import_id', $import->id)
                ->lazyById(500);

            foreach ($failures as $failure) {
                $original = json_encode(
                    $failure->original_row_data,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ) ?: '';
                fputcsv($stream, array_map($this->csvSafe(...), [
                    (string) $failure->row_number,
                    $original,
                    (string) $failure->column,
                    $failure->error_code,
                    $failure->error_message,
                    (string) $failure->sheet, (string) ($failure->sheet_row ?? $failure->row_number), (string) $failure->failure_type,
                ]), ',', '"', '', "\n");
            }

            rewind($stream);
            $disk = $import->disk;
            $path = trim((string) config('bulk-imports.files.directory', 'bulk-imports'), '/')
                ."/reports/{$import->id}/errors.csv";

            if (! $this->filesystems->disk($disk)->writeStream($path, $stream, [
                'visibility' => (string) config('bulk-imports.files.visibility', 'private'),
            ])) {
                throw new RuntimeException('Could not write the error report.');
            }

            Import::query()->whereKey($import->id)->update([
                'error_report_disk' => $disk,
                'error_report_path' => $path,
                'updated_at' => now(),
            ]);

            return compact('disk', 'path');
        } finally {
            fclose($stream);
        }
    }

    private function csvSafe(string $value): string
    {
        if (! config('bulk-imports.failures.formula_safe_csv', true)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/u', ltrim($value)) === 1 ? "'{$value}" : $value;
    }
}
