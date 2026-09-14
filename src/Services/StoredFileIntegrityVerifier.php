<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Filesystem\FilesystemManager;
use Nexus\ImportExport\Exceptions\FileValidationException;

final class StoredFileIntegrityVerifier
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function verify(string $disk, string $path, string $expectedSha256): void
    {
        $stream = $this->filesystems->disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new FileValidationException(['file' => ['The stored source file is not readable.']]);
        }

        $hash = hash_init('sha256');
        try {
            hash_update_stream($hash, $stream);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($expectedSha256, hash_final($hash))) {
            throw new FileValidationException(['file' => ['The stored source checksum does not match the accepted upload.']]);
        }
    }
}
