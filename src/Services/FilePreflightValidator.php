<?php

namespace Nexus\ImportExport\Services;

use Illuminate\Http\UploadedFile;
use Nexus\ImportExport\Exceptions\FileValidationException;

final class FilePreflightValidator
{
    public function validate(UploadedFile $file): void
    {
        $errors = [];
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('bulk-imports.files.allowed_extensions', ['csv', 'xlsx']);
        $allowedMimeTypes = config('bulk-imports.files.allowed_mime_types', []);
        $maxBytes = (int) config('bulk-imports.limits.max_file_size_kb', 102400) * 1024;

        if (! $file->isValid()) {
            $errors['file'][] = 'The uploaded file did not complete successfully.';
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            $errors['file'][] = 'The file extension is not allowed.';
        }

        if ($file->getSize() === false || $file->getSize() <= 0) {
            $errors['file'][] = 'The uploaded file is empty.';
        } elseif ($file->getSize() > $maxBytes) {
            $errors['file'][] = 'The uploaded file exceeds the configured size limit.';
        }

        $mime = $file->getMimeType();
        if ($mime !== null && $allowedMimeTypes !== [] && ! in_array($mime, $allowedMimeTypes, true)) {
            $errors['file'][] = "The detected MIME type [{$mime}] is not allowed.";
        }

        if (! is_readable((string) $file->getRealPath())) {
            $errors['file'][] = 'The uploaded temporary file is not readable.';
        }

        if ($errors !== []) {
            throw new FileValidationException($errors);
        }
    }
}
