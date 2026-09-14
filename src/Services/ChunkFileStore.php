<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final class ChunkFileStore
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    /**
     * @param  list<array{row_number:int,data:array<string,mixed>,business_key_hash:?string,duplicate_of_row:?int}>  $rows
     * @return array{path:string, checksum:string}
     */
    public function write(string $disk, string $importId, int $chunkNumber, array $rows): array
    {
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary chunk stream.');
        }

        $hash = hash_init('sha256');

        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
                hash_update($hash, $line);
                if (fwrite($stream, $line) === false) {
                    throw new RuntimeException('Could not write the temporary chunk stream.');
                }
            }

            rewind($stream);
            $path = trim((string) config('bulk-imports.files.directory', 'bulk-imports'), '/')
                ."/chunks/{$importId}/".str_pad((string) $chunkNumber, 8, '0', STR_PAD_LEFT).'.jsonl';

            if (! $this->filesystems->disk($disk)->writeStream($path, $stream, [
                'visibility' => (string) config('bulk-imports.files.visibility', 'private'),
            ])) {
                throw new RuntimeException('Could not persist the prepared chunk file.');
            }

            return ['path' => $path, 'checksum' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return iterable<array{row_number:int,data:array<string,mixed>,business_key_hash:?string,duplicate_of_row:?int}>
     */
    public function read(string $disk, string $path, string $expectedChecksum): iterable
    {
        $stream = $this->filesystems->disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Chunk file [{$path}] is not readable.");
        }

        $hash = hash_init('sha256');

        try {
            while (($line = fgets($stream)) !== false) {
                hash_update($hash, $line);
                /** @var array{row_number:int,data:array<string,mixed>,business_key_hash:?string,duplicate_of_row:?int} $row */
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                yield $row;
            }
        } finally {
            fclose($stream);
        }

        $actualChecksum = hash_final($hash);
        if (! hash_equals($expectedChecksum, $actualChecksum)) {
            throw new RuntimeException("Chunk checksum mismatch for [{$path}].");
        }
    }

    public function deleteImportDirectory(string $disk, string $importId): bool
    {
        $directory = trim((string) config('bulk-imports.files.directory', 'bulk-imports'), '/')
            ."/chunks/{$importId}";
        $filesystem = $this->filesystems->disk($disk);

        return ! $filesystem->directoryExists($directory) || $filesystem->deleteDirectory($directory);
    }
}
