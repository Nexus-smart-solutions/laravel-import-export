<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('bulk-imports.database.connection');
        $imports = config('bulk-imports.database.tables.imports', 'imports');
        $chunks = config('bulk-imports.database.tables.chunks', 'import_chunks');

        Schema::connection($connection)->create($chunks, function (Blueprint $table) use ($imports): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_id')->constrained($imports)->cascadeOnDelete();
            $table->unsignedInteger('chunk_number');
            $table->unsignedBigInteger('start_row');
            $table->unsignedBigInteger('end_row');
            $table->string('disk', 100);
            $table->string('file_path', 2048);
            $table->char('checksum', 64);
            $table->string('status', 40)->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('succeeded_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('staged_rows')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'chunk_number']);
            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.chunks', 'import_chunks'));
    }
};
