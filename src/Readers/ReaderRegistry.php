<?php

namespace Nexus\ImportExport\Readers;

use Nexus\ImportExport\Contracts\SourceReader;
use Nexus\ImportExport\Exceptions\FileValidationException;

final class ReaderRegistry
{
    /** @var list<SourceReader> */
    private array $readers = [];

    /** @param iterable<SourceReader> $readers */
    public function __construct(iterable $readers = [])
    {
        foreach ($readers as $reader) {
            $this->add($reader);
        }
    }

    public function add(SourceReader $reader): self
    {
        $this->readers[] = $reader;

        return $this;
    }

    public function for(string $extension, ?string $mimeType = null): SourceReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports(strtolower($extension), $mimeType)) {
                return $reader;
            }
        }

        throw new FileValidationException([
            'file' => ["No source reader is registered for .{$extension}."],
        ]);
    }
}
