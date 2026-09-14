<?php

namespace Nexus\ImportExport\Events;

use Illuminate\Support\Str;
use Nexus\ImportExport\Models\ImportChunk;

final class ImportChunkFailed extends ImportChunkEvent
{
    public string $reason;

    /** @param array<string, scalar|null> $context */
    public function __construct(ImportChunk $chunk, string $reason, array $context = [])
    {
        parent::__construct($chunk, $context);
        $this->reason = Str::limit($reason, 4000);
    }
}
