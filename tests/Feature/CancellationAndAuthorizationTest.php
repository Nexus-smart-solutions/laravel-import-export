<?php

namespace Nexus\ImportExport\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Nexus\ImportExport\Enums\ImportStatus;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Facades\BulkImport;
use Nexus\ImportExport\Services\CancelImport;
use Nexus\ImportExport\Tests\TestCase;

final class CancellationAndAuthorizationTest extends TestCase
{
    public function test_queued_import_can_be_cancelled_without_claiming_rollback_of_committed_data(): void
    {
        Queue::fake();
        $import = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([]))
            ->by($this->createActor())
            ->dispatch();

        $cancelled = app(CancelImport::class)->handle($import);

        self::assertSame(ImportStatus::CANCELLED, $cancelled->status);
        self::assertNotNull($cancelled->cancel_requested_at);
        self::assertNotNull($cancelled->cancelled_at);
    }

    public function test_default_authorizer_prevents_idor_for_show_and_failure_routes(): void
    {
        Queue::fake();
        $owner = $this->createActor('Owner');
        $attacker = $this->createActor('Attacker');
        $import = BulkImport::make(StudentsImport::class)
            ->file($this->uploadedCsv([]))
            ->by($owner)
            ->dispatch();

        $this->actingAs($owner)->getJson("/api/imports/{$import->id}")->assertOk();
        $this->actingAs($attacker)->getJson("/api/imports/{$import->id}")->assertForbidden();
        $this->actingAs($attacker)->getJson("/api/imports/{$import->id}/failures")->assertForbidden();
    }
}
