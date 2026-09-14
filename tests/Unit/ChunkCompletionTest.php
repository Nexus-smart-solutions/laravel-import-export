<?php

namespace Nexus\ImportExport\Tests\Unit;

use Nexus\ImportExport\Enums\ChunkStatus;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Enums\ImportMode;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Exceptions\ChunkLeaseUnavailable;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Models\ImportChunk;
use Nexus\ImportExport\Services\ChunkCompletion;
use Nexus\ImportExport\Tests\TestCase;

final class ChunkCompletionTest extends TestCase
{
    public function test_each_lease_owned_chunk_increments_progress_exactly_once(): void
    {
        $import = Import::query()->create([
            'type' => 'students', 'definition' => 'StudentsImport', 'original_filename' => 'x.csv',
            'disk' => 'imports-test', 'file_path' => 'x.csv', 'file_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64), 'status' => ImportStatus::PROCESSING,
            'deduplication_key' => str_repeat('e', 64),
            'mode' => ImportMode::PARTIAL, 'duplicate_strategy' => DuplicateStrategy::UPSERT,
            'context_hash' => str_repeat('c', 64), 'total_rows' => 2, 'total_chunks' => 2,
        ]);
        $chunks = [];
        $tokens = [];
        foreach ([1, 2] as $number) {
            $tokens[] = "00000000-0000-0000-0000-00000000000{$number}";
            $chunks[] = ImportChunk::query()->create([
                'import_id' => $import->id, 'chunk_number' => $number,
                'start_row' => $number + 1, 'end_row' => $number + 1,
                'disk' => 'imports-test', 'file_path' => "{$number}.jsonl",
                'checksum' => str_repeat((string) $number, 64),
                'status' => ChunkStatus::PROCESSING, 'total_rows' => 1,
                'lease_token' => $tokens[$number - 1],
            ]);
        }
        $completion = app(ChunkCompletion::class);

        $completion->complete($chunks[0], $tokens[0], 1, 0, 0, 0);
        $completion->complete($chunks[1], $tokens[1], 1, 0, 0, 0);
        self::assertSame(2, $import->refresh()->processed_rows);
        self::assertSame(2, $import->succeeded_rows);

        try {
            $completion->complete($chunks[0], $tokens[0], 1, 0, 0, 0);
            self::fail('A completed chunk must not increment the aggregate twice.');
        } catch (ChunkLeaseUnavailable) {
            self::assertSame(2, $import->refresh()->processed_rows);
            self::assertSame(2, $import->succeeded_rows);
        }
    }
}
