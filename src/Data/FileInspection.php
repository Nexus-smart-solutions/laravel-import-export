<?php

namespace Nexus\ImportExport\Data;

final readonly class FileInspection
{
    /** @param list<string> $headers */
    public function __construct(
        public array $headers,
        public ?int $totalRows,
        public ?string $worksheet = null,
    ) {}
}
