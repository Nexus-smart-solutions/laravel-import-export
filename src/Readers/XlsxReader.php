<?php

namespace Nexus\ImportExport\Readers;

use Nexus\ImportExport\Contracts\SourceReader;
use Nexus\ImportExport\Data\FileInspection;
use Nexus\ImportExport\Data\SourceRow;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Support\Header;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

final class XlsxReader implements SourceReader
{
    public function __construct(
        private readonly LocalFileMaterializer $materializer,
        private readonly XlsxSecurityInspector $security,
    ) {}

    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return $extension === 'xlsx';
    }

    public function inspect(string $disk, string $path, ?string $worksheet = null): FileInspection
    {
        return $this->materializer->run($disk, $path, function (string $localPath) use ($worksheet): FileInspection {
            $this->security->inspect($localPath);
            $reader = $this->newReader();
            $reader->open($localPath);

            $ordinal = 1;
            $expectedHeader = null;
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($worksheet === '*' && in_array($sheet->getName(), ['Instructions', '__options'], true)) {
                        continue;
                    }
                    if ($worksheet !== null && $worksheet !== '*' && $sheet->getName() !== $worksheet) {
                        continue;
                    }

                    foreach ($sheet->getRowIterator() as $row) {
                        $values = $row->toArray();
                        if ($this->isEmpty($values)) {
                            continue;
                        }

                        return new FileInspection($this->normalizeHeader($values), null, $sheet->getName());
                    }
                }
            } finally {
                $reader->close();
            }

            $message = $worksheet === null
                ? 'The XLSX file contains no readable worksheet with a header.'
                : "Required worksheet [{$worksheet}] was not found or is empty.";

            throw new FileValidationException(['file' => [$message]]);
        });
    }

    public function rows(string $disk, string $path, ?string $worksheet = null): iterable
    {
        yield from $this->materializer->iterate($disk, $path, function (string $localPath) use ($worksheet): iterable {
            $this->security->inspect($localPath);
            $reader = $this->newReader();
            $reader->open($localPath);

            $ordinal = 1;
            $expectedHeader = null;
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($worksheet === '*' && in_array($sheet->getName(), ['Instructions', '__options'], true)) {
                        continue;
                    }
                    if ($worksheet !== null && $worksheet !== '*' && $sheet->getName() !== $worksheet) {
                        continue;
                    }

                    $header = null;
                    $physicalRow = 0;

                    foreach ($sheet->getRowIterator() as $row) {
                        $physicalRow++;
                        $values = $row->toArray();

                        if ($header === null) {
                            if ($this->isEmpty($values)) {
                                continue;
                            }
                            $header = $this->normalizeHeader($values);
                            if (count(array_unique($header)) !== count($header)) {
                                throw new FileValidationException(['headers' => ['Duplicate worksheet headers.']]);
                            }
                            if ($expectedHeader !== null && array_map(Header::normalize(...), $expectedHeader) !== array_map(Header::normalize(...), $header)) {
                                throw new FileValidationException(['headers' => ['Data worksheets must have identical headers; child-resource sheets are unsupported.']]);
                            }
                            $expectedHeader = $header;

                            continue;
                        }

                        if ($this->isEmpty($values)) {
                            continue;
                        }

                        if (count($values) > count($header) && ! $this->isEmpty(array_slice($values, count($header)))) {
                            throw new FileValidationException(['headers' => ['A worksheet row contains values beyond its headers.']]);
                        }
                        $values = array_pad($values, count($header), null);
                        $values = array_slice($values, 0, count($header));
                        yield new SourceRow($worksheet === '*' ? ++$ordinal : $physicalRow, array_combine($header, $values), $sheet->getName(), $physicalRow);
                    }

                    if ($header !== null && $worksheet !== '*') {
                        return;
                    }
                }
            } finally {
                $reader->close();
            }
        });
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

    private function newReader(): Reader
    {
        // Preserving empty rows keeps physical spreadsheet row numbers accurate.
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return new Reader($options);
    }
}
