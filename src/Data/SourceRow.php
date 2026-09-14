<?php

namespace Nexus\ImportExport\Data;

final readonly class SourceRow
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $rowNumber,
        public array $data,
        public ?string $sheet = null,
        public ?int $sheetRow = null,
    ) {}
}
