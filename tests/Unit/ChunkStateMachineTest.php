<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;
use Nexus\ImportExport\State\ChunkStateMachine;
use PHPUnit\Framework\TestCase;

final class ChunkStateMachineTest extends TestCase
{
    public function test_completed_chunks_cannot_be_requeued_or_reprocessed(): void
    {
        $this->expectException(InvalidStateTransition::class);

        (new ChunkStateMachine)->assertCan(ChunkStatus::COMPLETED, ChunkStatus::PROCESSING);
    }

    public function test_failed_chunks_may_only_return_to_queued(): void
    {
        $machine = new ChunkStateMachine;
        $machine->assertCan(ChunkStatus::FAILED, ChunkStatus::QUEUED);

        $this->expectException(InvalidStateTransition::class);
        $machine->assertCan(ChunkStatus::FAILED, ChunkStatus::COMPLETED);
    }
}
