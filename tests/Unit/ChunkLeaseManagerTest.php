<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Services\ChunkLeaseManager;
use Nexus\ImportExport\Services\ImportLeaseManager;
use Nexus\ImportExport\Tests\TestCase;

final class ChunkLeaseManagerTest extends TestCase
{
    public function test_only_one_worker_can_claim_a_live_chunk_lease(): void
    {
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'x.csv',
            'disk' => 'imports-test', 'file_path' => 'x.csv', 'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'status' => ImportStatus::PROCESSING,
            'deduplication_key' => str_repeat('e', 64),
            'mode' => ImportMode::PARTIAL, 'duplicate_strategy' => DuplicateStrategy::UPSERT,
            'context_hash' => str_repeat('c', 64),
        ]);
        $chunk = ImportChunk::query()->create([
            'import_id' => $import->id, 'chunk_number' => 1, 'start_row' => 2, 'end_row' => 2,
            'disk' => 'imports-test', 'file_path' => 'chunk.jsonl', 'checksum' => str_repeat('d', 64),
            'status' => ChunkStatus::QUEUED, 'total_rows' => 1,
        ]);
        $leases = app(ChunkLeaseManager::class);

        self::assertNotNull($leases->claim($chunk->id, '11111111-1111-1111-1111-111111111111'));
        self::assertNull($leases->claim($chunk->id, '22222222-2222-2222-2222-222222222222'));
        self::assertSame(1, $chunk->fresh()->attempts);
    }

    public function test_only_one_worker_can_resume_the_batch_dispatch_crash_window(): void
    {
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'x.csv',
            'disk' => 'imports-test', 'file_path' => 'x.csv', 'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'status' => ImportStatus::PROCESSING,
            'deduplication_key' => str_repeat('e', 64),
            'mode' => ImportMode::PARTIAL, 'duplicate_strategy' => DuplicateStrategy::UPSERT,
            'context_hash' => str_repeat('c', 64),
        ]);
        $leases = app(ImportLeaseManager::class);

        self::assertNotNull($leases->claimBatchDispatch(
            $import->id,
            '11111111-1111-1111-1111-111111111111',
        ));
        self::assertNull($leases->claimBatchDispatch(
            $import->id,
            '22222222-2222-2222-2222-222222222222',
        ));
    }

    public function test_finalization_cannot_be_claimed_until_every_chunk_is_terminal(): void
    {
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'x.csv',
            'disk' => 'imports-test', 'file_path' => 'x.csv', 'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'status' => ImportStatus::PROCESSING,
            'deduplication_key' => str_repeat('f', 64),
            'mode' => ImportMode::PARTIAL, 'duplicate_strategy' => DuplicateStrategy::UPSERT,
            'context_hash' => str_repeat('c', 64),
        ]);
        $chunk = ImportChunk::query()->create([
            'import_id' => $import->id, 'chunk_number' => 1, 'start_row' => 2, 'end_row' => 2,
            'disk' => 'imports-test', 'file_path' => 'chunk.jsonl', 'checksum' => str_repeat('d', 64),
            'status' => ChunkStatus::QUEUED, 'total_rows' => 1,
        ]);
        $leases = app(ImportLeaseManager::class);

        self::assertNull($leases->claimFinalization(
            $import->id,
            '11111111-1111-1111-1111-111111111111',
        ));
        self::assertSame(ImportStatus::PROCESSING, $import->fresh()->status);

        $chunk->forceFill(['status' => ChunkStatus::COMPLETED])->save();
        $import->forceFill(['cancel_requested_at' => now()])->save();

        self::assertNull($leases->claimFinalization(
            $import->id,
            '22222222-2222-2222-2222-222222222222',
        ));
        self::assertSame(ImportStatus::PROCESSING, $import->fresh()->status);

        $import->forceFill(['cancel_requested_at' => null])->save();

        self::assertNotNull($leases->claimFinalization(
            $import->id,
            '33333333-3333-3333-3333-333333333333',
        ));
        self::assertSame(ImportStatus::FINALIZING, $import->fresh()->status);
    }
}
