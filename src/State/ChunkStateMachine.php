<?php

namespace Nexus\ImportExport\State;

use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;

final class ChunkStateMachine
{
    /** @var array<string, list<ChunkStatus>> */
    private const TRANSITIONS = [
        'queued' => [ChunkStatus::PROCESSING, ChunkStatus::CANCELLED],
        'processing' => [ChunkStatus::COMPLETED, ChunkStatus::FAILED, ChunkStatus::CANCELLED],
        'failed' => [ChunkStatus::QUEUED],
        'cancelled' => [],
        'completed' => [],
    ];

    public function assertCan(ChunkStatus $from, ChunkStatus $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new InvalidStateTransition("Chunk cannot transition from {$from->value} to {$to->value}.");
        }
    }
}
