<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('resource');
            $table->string('definition');
            $table->string('definition_version', 100);
            $table->string('format', 8);
            $table->string('disk');
            $table->string('file_path', 1024)->nullable();
            $table->string('filename');
            $table->string('status', 32)->index();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->char('context_hash', 64);
            $table->json('context');
            $table->json('request');
            $table->json('headers');
            $table->json('checkpoint')->nullable();
            $table->string('high_water')->nullable();
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedInteger('parts_count')->default(0);
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('read_complete')->default(false);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            foreach (['prepared_at', 'started_at', 'completed_at', 'failed_at', 'cancel_requested_at', 'expires_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['actor_type', 'actor_id', 'context_hash'], 'data_exports_owner');
            $table->index(['status', 'expires_at']);
        });
        $schema->create('data_export_parts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('export_id')->constrained('data_exports')->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->string('file_path', 1024);
            $table->char('checksum', 64);
            $table->unsignedInteger('rows');
            $table->unique(['export_id', 'part_number']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->dropIfExists('data_export_parts');
        $schema->dropIfExists('data_exports');
    }
};
