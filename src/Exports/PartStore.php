<?php

namespace Nexus\ImportExport\Exports;

use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\ExportPart;
use Nexus\ImportExport\Services\ChunkFileStore;

final class PartStore
{
    public function prefix(Export $export): string
    {
        return trim(config('import-export.exports.directory', 'bulk-exports'), '/').'/'.$export->id;
    }

    public function write(Export $export, string $token, iterable $rows): array
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        $hash = hash_init('sha256');
        $count = 0;
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new \RuntimeException('Failed to write export spool.');
                }
                hash_update($hash, $line);
                $count++;
            }
            rewind($stream);
            $path = $this->prefix($export).'/parts/'.$export->revision.'-'.$token.'.jsonl';
            if (! Storage::disk($export->disk)->writeStream($path, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Failed to store export spool.');
            }

            return ['file_path' => $path, 'checksum' => hash_final($hash), 'rows' => $count];
        } finally {
            fclose($stream);
        }
    }

    public function rows(Export $export, ExportPart $part): iterable
    {
        $count = 0;
        foreach (app(ChunkFileStore::class)->read($export->disk, $part->file_path, $part->checksum) as $row) {
            $count++;
            yield $row;
        }
        if ($count !== (int) $part->rows) {
            throw new \RuntimeException('Export part row count mismatch.');
        }
    }
}
