<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('bulk-imports.database.connection');
        $table = config('bulk-imports.database.tables.imports', 'imports');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 100)->index();
            $table->string('definition');
            $table->string('definition_version', 100)->default('1');
            $table->string('original_filename');
            $table->string('disk', 100);
            $table->string('file_path', 2048);
            $table->char('file_hash', 64)->index();
            $table->char('fingerprint', 64)->index();
            $table->char('deduplication_key', 64)->unique();
            $table->string('status', 40)->index();
            $table->string('mode', 20);
            $table->string('duplicate_strategy', 20);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('succeeded_rows')->default(0);
            $table->unsignedBigInteger('failed_rows')->default(0);
            $table->unsignedBigInteger('skipped_rows')->default(0);
            $table->unsignedBigInteger('staged_rows')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->index(['actor_type', 'actor_id']);
            $table->json('context')->nullable();
            $table->char('context_hash', 64)->index();
            $table->json('options')->nullable();
            $table->timestamp('preparation_dispatched_at')->nullable()->index();
            $table->uuid('queue_batch_id')->nullable()->index();
            $table->timestamp('batch_dispatched_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_stage', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_report_disk', 100)->nullable();
            $table->string('error_report_path', 2048)->nullable();
            $table->uuid('lease_token')->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamp('files_cleanup_started_at')->nullable()->index();
            $table->timestamp('files_deleted_at')->nullable()->index();
            $table->timestamp('failure_records_deleted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.imports', 'imports'));
    }
};
