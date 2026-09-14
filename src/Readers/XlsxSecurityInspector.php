<?php

namespace Nexus\ImportExport\Readers;

use Nexus\ImportExport\Exceptions\FileValidationException;
use ZipArchive;

final class XlsxSecurityInspector
{
    public function inspect(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new FileValidationException(['file' => ['The PHP zip extension is required for XLSX imports.']]);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new FileValidationException(['file' => ['The XLSX archive is corrupt or unreadable.']]);
        }

        if ($zip->numFiles > (int) config('bulk-imports.limits.xlsx_max_archive_entries', 10000)) {
            $zip->close();
            throw new FileValidationException(['file' => ['The XLSX archive contains too many entries.']]);
        }

        $totalCompressed = 0;
        $totalUncompressed = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    continue;
                }

                $name = str_replace('\\', '/', $stat['name']);
                if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':') || str_contains($name, chr(0))) {
                    throw new FileValidationException(['file' => ['Unsafe XLSX archive path.']]);
                }
                $totalCompressed += max(1, (int) ($stat['comp_size'] ?? 0));
                $totalUncompressed += (int) ($stat['size'] ?? 0);

                if ($totalUncompressed > (int) config('bulk-imports.limits.xlsx_max_uncompressed_bytes', 536870912)) {
                    throw new FileValidationException(['file' => ['The XLSX uncompressed size exceeds the configured safety limit.']]);
                }
            }
        } finally {
            $zip->close();
        }

        $ratio = $totalUncompressed / max(1, $totalCompressed);
        if ($ratio > (float) config('bulk-imports.limits.xlsx_max_compression_ratio', 100)) {
            throw new FileValidationException(['file' => ['The XLSX compression ratio exceeds the configured safety limit.']]);
        }
    }
}
