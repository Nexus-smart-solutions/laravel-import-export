<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->create('data_guest_tokens', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('token_hash', 64)->unique();
            $t->string('resource', 100);
            $t->char('context_hash', 64);
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->text('recipient_email')->nullable();
            $t->timestamp('recipient_verified_at')->nullable();
            $t->timestamp('created_at');
        });
        $schema->create('data_notification_receipts', function (Blueprint $t): void {
            $t->char('id', 64)->primary();
            $t->string('kind', 10);
            $t->ulid('operation_id');
            $t->string('status', 40);
            $t->json('context');
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('skipped_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code')->nullable();
            $t->timestamp('created_at')->index();
            $t->timestamp('updated_at');
            $t->index(['sent_at', 'skipped_at', 'updated_at'], 'notice_recovery');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->dropIfExists('data_notification_receipts');
        $schema->dropIfExists('data_guest_tokens');
    }
};
