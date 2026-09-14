<?php

namespace Nexus\ImportExport\Readers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Nexus\ImportExport\Contracts\SourceReader;
use Nexus\ImportExport\Data\FileInspection;
use Nexus\ImportExport\Data\SourceRow;
use Nexus\ImportExport\Exceptions\FileValidationException;

final class CsvReader implements SourceReader
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return $extension === 'csv';
    }

    public function inspect(string $disk, string $path, ?string $worksheet = null): FileInspection
    {
        [$stream, $delimiter] = $this->open($this->filesystems->disk($disk), $path);

        try {
            $headerRecord = $this->nextNonEmptyRecord($stream, $delimiter);
        } finally {
            fclose($stream);
        }

        if ($headerRecord === null) {
            throw new FileValidationException(['file' => ['The CSV file contains no header row.']]);
        }

        return new FileInspection($this->normalizeHeader($headerRecord['values']), null);
    }

    public function rows(string $disk, string $path, ?string $worksheet = null): iterable
    {
        [$stream, $delimiter] = $this->open($this->filesystems->disk($disk), $path);

        try {
            $headerRecord = $this->nextNonEmptyRecord($stream, $delimiter);
            if ($headerRecord === null) {
                return;
            }

            $header = $this->normalizeHeader($headerRecord['values']);
            $physicalRow = $headerRecord['row_number'];

            while (($values = $this->record($stream, $delimiter)) !== null) {
                $physicalRow++;
                if (count($values) > count($header)) {
                    throw new FileValidationException(['headers' => ['A CSV row contains more values than headers.']]);
                }
                $values = array_pad($values, count($header), null);
                $values = array_slice($values, 0, count($header));

                if ($this->isEmpty($values)) {
                    continue;
                }

                yield new SourceRow($physicalRow, array_combine($header, $values));
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return array{resource, string} */
    private function open(Filesystem $filesystem, string $path): array
    {
        $stream = $filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw new FileValidationException(['file' => ['The stored CSV file is not readable.']]);
        }

        $sample = '';
        while (($line = fgets($stream, (int) config('bulk-imports.limits.max_csv_record_bytes', 2097152) + 2)) !== false) {
            if (trim($line) !== '') {
                $sample = $line;
                break;
            }
        }
        fclose($stream);

        $delimiter = $this->detectDelimiter($sample);
        $stream = $filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw new FileValidationException(['file' => ['The stored CSV file is not readable.']]);
        }

        return [$stream, $delimiter];
    }

    /** Bounded RFC 4180 records, including embedded newlines. */
    private function record($stream, string $delimiter): ?array
    {
        $max = (int) config('bulk-imports.limits.max_csv_record_bytes', 2097152);
        $record = '';
        do {
            $line = fgets($stream, $max + 2);
            if ($line === false) {
                if ($record === '') {
                    return null;
                }
                throw new FileValidationException(['file' => ['Unclosed quoted CSV record.']]);
            }
            $record .= $line;
            if (strlen($record) > $max) {
                throw new FileValidationException(['file' => ['CSV record exceeds its byte limit.']]);
            }
        } while (substr_count($record, '"') % 2 !== 0);
        $values = str_getcsv($record, $delimiter, '"', '');
        if (count($values) > (int) config('bulk-imports.limits.max_columns', 250)) {
            throw new FileValidationException(['file' => ['Too many CSV columns.']]);
        }

        return $values;
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $scores[$candidate] = count(str_getcsv($line, $candidate, '"', ''));
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    /** @return null|array{values:list<string|null>,row_number:int} */
    private function nextNonEmptyRecord($stream, string $delimiter): ?array
    {
        $rowNumber = 0;
        while (($row = $this->record($stream, $delimiter)) !== null) {
            $rowNumber++;
            if (! $this->isEmpty($row)) {
                return ['values' => $row, 'row_number' => $rowNumber];
            }
        }

        return null;
    }

    /** @param list<mixed> $values */
    private function isEmpty(array $values): bool
    {
        return collect($values)->every(static fn (mixed $value): bool => match (true) {
            $value === null => true,
            is_string($value) => trim($value) === '',
            default => false,
        });
    }

    /** @param list<mixed> $header */
    private function normalizeHeader(array $header): array
    {
        return array_map(
            static fn (mixed $value): string => trim(str_replace("\xEF\xBB\xBF", '', (string) $value)),
            $header,
        );
    }
}
