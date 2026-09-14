<?php

namespace Nexus\ImportExport\Readers;

use Closure;
use Illuminate\Filesystem\FilesystemManager;
use Nexus\ImportExport\Exceptions\FileValidationException;

final class LocalFileMaterializer
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function run(string $disk, string $path, Closure $callback): mixed
    {
        $temporaryPath = $this->copyToTemporaryPath($disk, $path);

        try {
            return $callback($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /** @return iterable<mixed> */
    public function iterate(string $disk, string $path, Closure $factory): iterable
    {
        $temporaryPath = $this->copyToTemporaryPath($disk, $path);

        try {
            yield from $factory($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function copyToTemporaryPath(string $disk, string $path): string
    {
        $input = $this->filesystems->disk($disk)->readStream($path);

        if (! is_resource($input)) {
            throw new FileValidationException(['file' => ['The stored source file is not readable.']]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'bulk-import-');
        if ($temporaryPath === false) {
            fclose($input);
            throw new FileValidationException(['file' => ['A local temporary file could not be created.']]);
        }

        $output = fopen($temporaryPath, 'wb');
        if ($output === false) {
            fclose($input);
            @unlink($temporaryPath);
            throw new FileValidationException(['file' => ['A local temporary file could not be opened.']]);
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        return $temporaryPath;
    }
}
