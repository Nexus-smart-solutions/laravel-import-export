<?php

namespace Nexus\ImportExport\Writers;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

final class SpreadsheetWriter
{
    public static function safeCsv(string $value): string
    {
        if (preg_match('/^[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/D', $value)) {
            return $value;
        }

        return config('import-export.csv.formula_safe', true) && preg_match('/^[\s\p{Z}]*[=+@\-]/u', $value) ? "'".$value : $value;
    }

    public static function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => new StringCell($value === null ? '' : (string) $value, null), $values));
    }

    public function write(string $path, string $format, array $headers, iterable $rows): void
    {
        if ($format === 'csv') {
            $stream = fopen($path, 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open export file.');
            }
            try {
                if (config('import-export.csv.bom', true)) {
                    fwrite($stream, "\xEF\xBB\xBF");
                }
                $write = function (array $row) use ($stream): void {
                    $result = fputcsv($stream, array_map(fn ($v) => self::safeCsv((string) $v), $row), config('import-export.csv.delimiter', ','), '"', '', config('import-export.csv.line_ending', "\r\n"));
                    if ($result === false) {
                        throw new \RuntimeException('Cannot write CSV file.');
                    }
                };
                $write($headers);
                foreach ($rows as $row) {
                    $write($row);
                }
            } finally {
                fclose($stream);
            }

            return;
        }
        if ($format !== 'xlsx') {
            throw new \InvalidArgumentException('Use csv or xlsx.');
        }
        $options = new Options();
        $options->SHOULD_CREATE_NEW_SHEETS_AUTOMATICALLY = false;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $sheet = 1;
        $count = 1;
        $limit = max(2, min(1048576, (int) config('import-export.xlsx.rows_per_sheet', 1048576)));
        try {
            $writer->getCurrentSheet()->setName('Data 1');
            $writer->addRow(self::row($headers));
            foreach ($rows as $row) {
                if ($count >= $limit) {
                    $writer->addNewSheetAndMakeItCurrent()->setName('Data '.++$sheet);
                    $writer->addRow(self::row($headers));
                    $count = 1;
                }
                foreach ($row as $value) {
                    if (mb_strlen((string) $value) > 32767) {
                        throw new \RuntimeException('An XLSX cell exceeds Excel\'s 32767-character limit; use CSV.');
                    }
                }
                $writer->addRow(self::row($row));
                $count++;
            }
        } finally {
            $writer->close();
        }
    }
}
