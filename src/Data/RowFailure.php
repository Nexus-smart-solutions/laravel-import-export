<?php

namespace Nexus\ImportExport\Data;

final readonly class RowFailure
{
    /** @param array<string, mixed> $row */
    public function __construct(
        public int $rowNumber,
        public ?string $column,
        public string $code,
        public string $message,
        public array $row,
        public ?string $sheet = null,
        public ?int $sheetRow = null,
        public ?array $normalized = null,
    ) {}
}
