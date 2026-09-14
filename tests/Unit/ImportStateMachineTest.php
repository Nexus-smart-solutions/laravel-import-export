<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Exceptions\InvalidStateTransition;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\State\ImportStateMachine;
use Nexus\ImportExport\Tests\TestCase;

final class ImportStateMachineTest extends TestCase
{
    public function test_it_allows_only_declared_transitions(): void
    {
        $machine = app(ImportStateMachine::class);

        self::assertTrue($machine->can(ImportStatus::QUEUED, ImportStatus::VALIDATING));
        self::assertTrue($machine->can(ImportStatus::PROCESSING, ImportStatus::FINALIZING));
        self::assertFalse($machine->can(ImportStatus::QUEUED, ImportStatus::COMPLETED));
        self::assertFalse($machine->can(ImportStatus::COMPLETED, ImportStatus::PROCESSING));
    }

    public function test_invalid_transition_throws_and_does_not_mutate_state(): void
    {
        $import = $this->makeImport(ImportStatus::QUEUED);

        $this->expectException(InvalidStateTransition::class);
        try {
            app(ImportStateMachine::class)->transition($import, ImportStatus::COMPLETED);
        } finally {
            self::assertSame(ImportStatus::QUEUED, $import->fresh()->status);
        }
    }

    private function makeImport(ImportStatus $status): Import
    {
        return Import::query()->create([
            'type' => 'students',
            'definition' => 'StudentsImport',
            'original_filename' => 'students.csv',
            'disk' => 'imports-test',
            'file_path' => 'students.csv',
            'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64),
            'deduplication_key' => str_repeat('e', 64),
            'status' => $status,
            'mode' => ImportMode::PARTIAL,
            'duplicate_strategy' => DuplicateStrategy::UPSERT,
            'context_hash' => str_repeat('c', 64),
        ]);
    }
}
