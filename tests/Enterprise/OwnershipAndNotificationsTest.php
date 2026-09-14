<?php

namespace Nexus\ImportExport\Tests\Enterprise;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nexus\ImportExport\Auth\GuestAccess;
use Nexus\ImportExport\Auth\SystemPrincipal;
use Nexus\ImportExport\ImportExportServiceProvider;
use Nexus\ImportExport\Contracts\ContextRestorer;
use Nexus\ImportExport\Contracts\CurrentContext;
use Nexus\ImportExport\Contracts\NotificationRecipientResolver;
use Nexus\ImportExport\Examples\Enterprise\Definitions\StudentDefinition;
use Nexus\ImportExport\ExportManager;
use Nexus\ImportExport\ImportManager;
use Nexus\ImportExport\Jobs\CompletionNoticeJob;
use Nexus\ImportExport\Models\Export;
use Nexus\ImportExport\Models\Import;
use Nexus\ImportExport\Notifications\CreatorRecipientResolver;
use Nexus\ImportExport\Notifications\NotificationDispatcher;
use Nexus\ImportExport\Notifications\OperationFinished;
use Nexus\ImportExport\TemplateManager;
use PHPUnit\Framework\Assert;

final class OwnershipAndNotificationsTest extends EnterpriseTestCase
{
    private function guests(): GuestAccess
    {
        config(['import-export.guests.enabled' => true, 'bulk-imports.definitions.students' => GuestStudents::class]);

        return app(GuestAccess::class);
    }

    private function token(): string
    {
        return $this->postJson('/api/data/resources/students/guest-token')->assertCreated()->json('data.token');
    }

    public function test_creator_only_details_controls_and_lists_without_tenancy(): void
    {
        $owner = $this->createActor('Owner');
        $other = $this->createActor('Other');
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $owner);
        $export = app(ExportManager::class)->dispatch('students', $owner);
        self::assertSame([], $import->context);
        self::assertSame([], $export->context);
        $this->actingAs($other)->getJson('/api/data/imports/'.$import->id)->assertForbidden();
        $this->getJson('/api/data/imports/'.$import->id.'/failures')->assertForbidden();
        $this->postJson('/api/data/imports/'.$import->id.'/cancel')->assertForbidden();
        $this->getJson('/api/data/exports/'.$export->id)->assertForbidden();
        $this->getJson('/api/data/exports/'.$export->id.'/download')->assertForbidden();
        $this->postJson('/api/data/exports/'.$export->id.'/retry')->assertForbidden();
        $this->getJson('/api/data/imports')->assertJsonCount(0, 'data');
        $this->getJson('/api/data/exports')->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson('/api/data/imports/'.$import->id)->assertOk();
        $this->getJson('/api/data/exports/'.$export->id)->assertOk();
        $this->getJson('/api/data/imports')->assertJsonCount(1, 'data');
        $this->getJson('/api/data/exports')->assertJsonCount(1, 'data');
    }

    public function test_guest_access_requires_global_and_definition_opt_in(): void
    {
        $this->getJson('/api/data/resources/students')->assertUnauthorized();
        $this->postJson('/api/data/resources/students/guest-token')->assertForbidden();
        config(['import-export.guests.enabled' => true]);
        $this->postJson('/api/data/resources/students/guest-token')->assertForbidden();
    }

    public function test_guest_tokens_are_random_hashed_and_not_exposed_by_status(): void
    {
        $guests = $this->guests();
        $one = $guests->issue('students');
        $two = $guests->issue('students');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $one['token']);
        self::assertNotSame($one['token'], $two['token']);
        self::assertSame(hash('sha256', $one['token']), $guests->tokens()->where('id', $one['principal']->id)->value('token_hash'));
        self::assertStringNotContainsString($one['token'], json_encode($guests->tokens()->get()));
        $export = app(ExportManager::class)->dispatch('students', $one['principal']);
        $this->withToken($one['token'])->getJson('/api/data/exports/'.$export->id)->assertOk()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.actor_id');
    }

    public function test_guest_owns_import_export_template_and_download(): void
    {
        $guests = $this->guests();
        $token = $this->token();
        $principal = $guests->resolve($token);
        self::assertNotNull($principal);
        $response = $this->withToken($token)->post('/api/data/resources/students/imports', ['file' => $this->csv([['A', 'A', 'active', 'SCH001']])], ['Accept' => 'application/json'])->assertStatus(202);
        $import = Import::query()->findOrFail($response->json('data.id'));
        self::assertSame($principal->id, $import->actor_id);
        $this->getJson('/api/data/imports/'.$import->id)->assertOk();
        $export = $this->postJson('/api/data/resources/students/exports', ['fields' => ['name']])->assertStatus(202)->json('data.id');
        $this->getJson('/api/data/exports/'.$export.'/download')->assertOk();
        $this->getJson('/api/data/resources/students/template')->assertOk();
    }

    public function test_cross_guest_and_cross_resource_access_are_denied(): void
    {
        $guests = $this->guests();
        $one = $guests->issue('students');
        $two = $guests->issue('students');
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $one['principal']);
        $export = app(ExportManager::class)->dispatch('students', $one['principal']);
        $this->withToken($two['token'])->getJson('/api/data/imports/'.$import->id)->assertForbidden();
        $this->getJson('/api/data/exports/'.$export->id)->assertForbidden();
        $this->postJson('/api/data/exports/'.$export->id.'/cancel')->assertForbidden();
        $this->getJson('/api/data/resources/products/template')->assertForbidden();
    }

    public function test_expired_guest_token_fails_in_http_and_direct_service_calls(): void
    {
        $guests = $this->guests();
        $issued = $guests->issue('students');
        $guests->tokens()->where('id', $issued['principal']->id)->update(['expires_at' => now()->subSecond()]);
        $this->withToken($issued['token'])->getJson('/api/data/resources/students')->assertUnauthorized();
        $this->expectException(AuthorizationException::class);
        app(TemplateManager::class)->generate('students', $issued['principal']);
    }

    public function test_guest_can_revoke_own_credential(): void
    {
        $this->guests();
        $token = $this->token();
        $this->withToken($token)->deleteJson('/api/data/guest-token')->assertNoContent();
        $this->getJson('/api/data/resources/students')->assertUnauthorized();
    }

    public function test_logged_in_identity_takes_precedence_over_guest_token(): void
    {
        $guests = $this->guests();
        $issued = $guests->issue('students');
        $guestExport = app(ExportManager::class)->dispatch('students', $issued['principal']);
        $user = $this->createActor();
        $this->actingAs($user)->withToken($issued['token'])->getJson('/api/data/exports/'.$guestExport->id)->assertForbidden();
        $this->postJson('/api/data/resources/students/exports')->assertStatus(202);
        self::assertSame((string) $user->id, Export::query()->where('id', '!=', $guestExport->id)->sole()->actor_id);
    }

    public function test_forged_owner_context_and_recipient_fields_are_rejected(): void
    {
        $this->actingAs($this->createActor());
        foreach (['actor_id', 'tenant_id', 'workspace_id', 'context', 'recipient_email'] as $key) {
            $this->postJson('/api/data/resources/students/exports', [$key => 'forged'])->assertUnprocessable();
            $this->postJson('/api/data/resources/students/imports', ['options' => [$key => 'forged']])->assertUnprocessable();
        }
        $this->postJson('/api/data/resources/students/guest-token', ['context' => ['tenant_id' => 99]])->assertUnprocessable();
    }

    public function test_guest_tokens_are_bound_to_trusted_context(): void
    {
        $guests = $this->guests();
        $context = new MutableAccessContext;
        $this->app->instance(CurrentContext::class, $context);
        $issued = $guests->issue('students');
        self::assertNotNull($guests->resolve($issued['token']));
        $context->tenant = 8;
        self::assertNull($guests->resolve($issued['token']));
        $this->withToken($issued['token'])->getJson('/api/data/resources/students')->assertUnauthorized();
    }

    public function test_guest_disabled_after_issuance_is_denied(): void
    {
        $guests = $this->guests();
        $issued = $guests->issue('students');
        config(['bulk-imports.definitions.students' => StudentDefinition::class]);
        self::assertNull($guests->resolve($issued['token']));
        $this->expectException(AuthorizationException::class);
        app(ExportManager::class)->dispatch('students', $issued['principal']);
    }

    public function test_cli_system_operations_keep_explicit_ownership(): void
    {
        $system = new SystemPrincipal('nightly.students');
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $system);
        $export = app(ExportManager::class)->dispatch('students', $system);
        self::assertSame('completed', $import->status->value);
        self::assertSame('completed', $export->status->value);
        self::assertSame(SystemPrincipal::MORPH_TYPE, $export->actor_type);
        $this->actingAs($this->createActor())->getJson('/api/data/exports/'.$export->id)->assertForbidden();
    }

    public function test_guest_credentials_cannot_be_serialized_into_jobs(): void
    {
        $principal = $this->guests()->issue('students')['principal'];
        $this->expectException(\LogicException::class);
        serialize($principal);
    }

    public function test_notifications_are_disabled_by_default(): void
    {
        Notification::fake();
        $owner = $this->createActor();
        app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $owner);
        app(ExportManager::class)->dispatch('students', $owner);
        Notification::assertNothingSent();
        self::assertSame(0, app(NotificationDispatcher::class)->receipts()->count());
    }

    public function test_notifications_resolve_stored_creator_and_deduplicate_jobs(): void
    {
        Notification::fake();
        config(['import-export.notifications.enabled' => true]);
        Queue::fake([CompletionNoticeJob::class]);
        $owner = $this->createActor('Owner');
        $other = $this->createActor('Other');
        app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $owner);
        app(ExportManager::class)->dispatch('students', $owner);
        $this->actingAs($other);
        $jobs = Queue::pushed(CompletionNoticeJob::class);
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->app->call([$job, 'handle']);
            $this->app->call([$job, 'handle']);
        }
        Notification::assertSentToTimes($owner, OperationFinished::class, 2);
        Notification::assertNotSentTo($other, OperationFinished::class);
    }

    public function test_notification_failure_does_not_fail_successful_import_or_export(): void
    {
        config(['import-export.notifications.enabled' => true]);
        $this->app->instance(NotificationRecipientResolver::class, new class implements NotificationRecipientResolver
        {
            public function resolve(Import|Export $operation): ?object
            {
                throw new \RuntimeException('Provider unavailable');
            }
        });
        $owner = $this->createActor();
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $owner);
        $export = app(ExportManager::class)->dispatch('students', $owner);
        self::assertSame('completed', $import->status->value);
        self::assertSame('completed', $export->status->value);
        self::assertSame(1, $import->processed_rows);
        self::assertSame(1, $export->processed_rows);
        self::assertSame(2, app(NotificationDispatcher::class)->receipts()->where('error_code', 'delivery_failed')->count());
    }

    public function test_verified_guest_recipient_is_encrypted_and_receives_mail_notice(): void
    {
        Notification::fake();
        config(['import-export.notifications.enabled' => true]);
        $guests = $this->guests();
        $issued = $guests->issue('students');
        app(ExportManager::class)->dispatch('students', $issued['principal']);
        Notification::assertNothingSent();
        $guests->setVerifiedRecipient($issued['principal'], 'verified@example.test');
        self::assertNotSame('verified@example.test', $guests->tokens()->value('recipient_email'));
        app(ExportManager::class)->dispatch('students', $issued['principal']);
        Notification::assertSentOnDemand(OperationFinished::class, fn ($notice, $channels, $recipient) => $recipient->routes['mail'] === 'verified@example.test' && $channels === ['mail']);
    }

    public function test_database_notification_channel_writes_only_safe_metadata(): void
    {
        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        config(['import-export.notifications.enabled' => true, 'import-export.notifications.channels' => ['database']]);
        $owner = $this->createActor();
        $export = app(ExportManager::class)->dispatch('students', $owner);
        $notice = DB::table('notifications')->sole();
        self::assertSame((int) $owner->id, (int) $notice->notifiable_id);
        $data = json_decode($notice->data, true);
        self::assertSame($export->id, $data['id']);
        self::assertArrayNotHasKey('file_path', $data);
        self::assertArrayNotHasKey('context', $data);
    }

    public function test_custom_notification_class_and_channels_are_supported(): void
    {
        Notification::fake();
        config(['import-export.notifications.enabled' => true, 'import-export.notifications.notification_class' => CustomNotice::class, 'import-export.notifications.channels' => ['mail']]);
        $owner = $this->createActor();
        app(ExportManager::class)->dispatch('students', $owner);
        Notification::assertSentTo($owner, CustomNotice::class, fn ($notice, $channels) => $channels === ['mail'] && $notice->toMail($owner)->subject === 'Custom completion');
    }

    public function test_context_restored_before_recipient_resolution_and_torn_down(): void
    {
        Notification::fake();
        config(['import-export.notifications.enabled' => true]);
        Queue::fake([CompletionNoticeJob::class]);
        $context = new MutableAccessContext;
        $this->app->instance(CurrentContext::class, $context);
        $owner = $this->createActor();
        app(ExportManager::class)->dispatch('students', $owner);
        $this->app->instance(ContextRestorer::class, new class($context) implements ContextRestorer
        {
            public function __construct(private MutableAccessContext $current) {}

            public function run(array $context, \Closure $callback): mixed
            {
                $previous = $this->current->tenant;
                $this->current->tenant = $context['tenant_id'];
                try {
                    return $callback();
                } finally {
                    $this->current->tenant = $previous;
                }
            }
        });
        $this->app->instance(NotificationRecipientResolver::class, new class($owner, $context) implements NotificationRecipientResolver
        {
            public function __construct(private object $owner, private MutableAccessContext $context) {}

            public function resolve(Import|Export $operation): ?object
            {
                Assert::assertSame(7, $this->context->tenant);

                return $this->owner;
            }
        });
        $context->tenant = 999;
        $this->app->call([Queue::pushed(CompletionNoticeJob::class)->sole(), 'handle']);
        self::assertSame(999, $context->tenant);
        Notification::assertSentToTimes($owner, OperationFinished::class, 1);
    }

    public function test_broker_failure_cannot_escape_notification_dispatcher(): void
    {
        config(['import-export.notifications.enabled' => true]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue offline'));
        app(NotificationDispatcher::class)->queue('export', '01K00000000000000000000000', 'completed', []);
        self::assertSame(1, app(NotificationDispatcher::class)->receipts()->count());
    }

    public function test_failed_notification_receipt_can_retry_without_reprocessing_business_data(): void
    {
        Notification::fake();
        config(['import-export.notifications.enabled' => true]);
        $this->app->instance(NotificationRecipientResolver::class, new class implements NotificationRecipientResolver
        {
            public function resolve(Import|Export $operation): ?object
            {
                throw new \RuntimeException('Temporary failure');
            }
        });
        $owner = $this->createActor();
        $export = app(ExportManager::class)->dispatch('students', $owner);
        self::assertSame('completed', $export->status->value);
        $this->app->instance(NotificationRecipientResolver::class, new CreatorRecipientResolver);
        app(NotificationDispatcher::class)->receipts()->update(['updated_at' => now()->subMinutes(3)]);
        $this->artisan('import-export:retry-notifications')->assertSuccessful();
        Notification::assertSentToTimes($owner, OperationFinished::class, 1);
        self::assertSame(2, (int) app(NotificationDispatcher::class)->receipts()->value('attempts'));
        self::assertSame('completed', $export->refresh()->status->value);
    }

    public function test_after_commit_queue_failure_does_not_roll_back_committed_work(): void
    {
        config(['import-export.notifications.enabled' => true]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Broker unavailable'));
        DB::transaction(function (): void {
            DB::table('countries')->insert(['code' => 'TX', 'name' => 'Committed']);
            app(NotificationDispatcher::class)->queue('export', '01K00000000000000000000000', 'completed', []);
        });
        self::assertSame('Committed', DB::table('countries')->where('code', 'TX')->value('name'));
        self::assertSame(1, app(NotificationDispatcher::class)->receipts()->count());
    }

    public function test_rolled_back_operation_does_not_queue_notification(): void
    {
        config(['import-export.notifications.enabled' => true]);
        Bus::fake();
        try {
            DB::transaction(function (): void {
                app(NotificationDispatcher::class)->queue('export', '01K00000000000000000000000', 'completed', []);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        Bus::assertNotDispatched(CompletionNoticeJob::class);
        self::assertSame(0, app(NotificationDispatcher::class)->receipts()->count());
    }

    public function test_live_notification_lease_excludes_duplicate_delivery(): void
    {
        Notification::fake();
        $owner = $this->createActor();
        $export = app(ExportManager::class)->dispatch('students', $owner);
        config(['import-export.notifications.enabled' => true]);
        $dispatcher = app(NotificationDispatcher::class);
        $id = $dispatcher->record('export', $export->id, 'completed', []);
        $dispatcher->receipts()->where('id', $id)->update(['lease_token' => 'other-worker', 'lease_expires_at' => now()->addMinute()]);
        $job = new CompletionNoticeJob('export', $export->id, 'completed', []);
        $this->app->call([$job, 'handle']);
        Notification::assertNothingSent();
        $dispatcher->receipts()->where('id', $id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);
        Notification::assertSentToTimes($owner, OperationFinished::class, 1);
    }

    public function test_migrations_and_counters_are_installable_and_exposed(): void
    {
        $paths = ServiceProvider::pathsToPublish(ImportExportServiceProvider::class, 'bulk-imports-migrations');
        self::assertCount(count(glob(dirname(__DIR__, 2).'/database/migrations/*.php')), $paths);
        $owner = $this->createActor();
        $import = app(ImportManager::class)->dispatch('students', $this->csv([['A', 'A', 'active', 'SCH001']]), $owner);
        self::assertSame(1, $import->inserted_rows);
        self::assertSame(1, $import->processed_chunks);
        $this->actingAs($owner)->getJson('/api/data/imports/'.$import->id)->assertJsonPath('data.progress.insertedRows', 1);
    }
}
final class GuestStudents extends StudentDefinition
{
    public function allowsGuests(): bool
    {
        return true;
    }
}
final class MutableAccessContext implements CurrentContext
{
    public int $tenant = 7;

    public function get(): array
    {
        return ['tenant_id' => $this->tenant];
    }
}
final class CustomNotice extends OperationFinished
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Custom completion');
    }
}
