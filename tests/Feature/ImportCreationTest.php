<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\Enums\IdempotencyStrategy;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Exceptions\FileValidationException;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Jobs\PrepareImportJob;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Tests\TestCase;

final class ImportCreationTest extends TestCase
{
    public function test_upload_is_stored_and_request_work_is_queued_without_processing_rows(): void
    {
        Queue::fake();
        $actor = $this->createActor();

        $import = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([
                ['STU-1', 'Student One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01'],
            ]))
            ->by($actor)
            ->context(['tenant_id' => 44])
            ->dispatch();

        self::assertSame(ImportStatus::QUEUED, $import->status);
        self::assertSame(0, $import->processed_rows);
        self::assertSame((string) $actor->getKey(), $import->actor_id);
        Storage::disk('imports-test')->assertExists($import->file_path);
        Queue::assertPushed(PrepareImportJob::class, fn (PrepareImportJob $job): bool => $job->importId === $import->id);
    }

    public function test_fingerprint_can_return_existing_or_explicitly_allow_another_import(): void
    {
        Queue::fake();
        $actor = $this->createActor();
        $rows = [['STU-1', 'Student One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01']];

        $first = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))->by($actor)->context(['tenant_id' => 1])->dispatch();
        $replayed = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))->by($actor)->context(['tenant_id' => 1])->dispatch();
        $allowed = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))->by($actor)->context(['tenant_id' => 1])
            ->idempotency(IdempotencyStrategy::ALLOW)->dispatch();

        self::assertSame($first->id, $replayed->id);
        self::assertNotSame($first->id, $allowed->id);
        self::assertSame(2, Import::query()->count());
    }

    public function test_preflight_rejects_an_unregistered_extension_before_storage_or_database_writes(): void
    {
        Queue::fake();
        $this->expectException(FileValidationException::class);

        BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([], 'students.exe'))
            ->by($this->createActor())
            ->dispatch();
    }

    public function test_default_fingerprint_scope_does_not_return_another_actors_protected_import(): void
    {
        Queue::fake();
        $rows = [['STU-1', 'Student One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01']];

        $first = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))->by($this->createActor('First'))->context(['tenant_id' => 1])->dispatch();
        $second = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))->by($this->createActor('Second'))->context(['tenant_id' => 1])->dispatch();

        self::assertNotSame($first->id, $second->id);
    }

    public function test_optional_api_maps_explicit_duplicate_rejection_to_conflict(): void
    {
        Queue::fake();
        $actor = $this->createActor();
        $rows = [['STU-1', 'Student One', 'one@example.test', '+201000000001', '2000-01-01', 'COURSE-01']];
        $existing = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv($rows))
            ->by($actor)
            ->dispatch();

        $this->actingAs($actor)->post('/api/imports', [
            'type' => 'students',
            'file' => $this->uploadedCsv($rows),
            'idempotency' => 'reject',
        ])->assertStatus(409)->assertJsonPath('data.id', $existing->id);
    }

    public function test_sync_queue_is_rejected_unless_explicitly_enabled_for_tests(): void
    {
        config()->set('bulk-imports.queue.allow_sync', false);
        $this->expectException(ImportConfigurationException::class);

        BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([]))
            ->by($this->createActor())
            ->dispatch();
    }
}
