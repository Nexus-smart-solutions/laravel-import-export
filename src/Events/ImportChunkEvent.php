<?php

namespace Nexus\ImportExport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nexus\ImportExport\Models\ImportChunk;

abstract class ImportChunkEvent
{
    use Dispatchable;

    public string $chunkId;

    public string $importId;

    public int $chunkNumber;

    public string $status;

    /** @var array<string, scalar|null> */
    public array $context;

    /** @var array<string, int> */
    public array $counters;

    /** @param array<string, scalar|null> $context */
    public function __construct(ImportChunk $chunk, array $context = [])
    {
        $this->chunkId = $chunk->id;
        $this->importId = $chunk->import_id;
        $this->chunkNumber = (int) $chunk->chunk_number;
        $this->status = $chunk->status->value;
        $this->context = $context;
        $this->counters = [
            'total' => (int) $chunk->total_rows,
            'processed' => (int) $chunk->processed_rows,
            'succeeded' => (int) $chunk->succeeded_rows,
            'failed' => (int) $chunk->failed_rows,
            'skipped' => (int) $chunk->skipped_rows,
            'staged' => (int) $chunk->staged_rows,
        ];
    }
}
