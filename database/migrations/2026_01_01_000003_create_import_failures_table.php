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
        $failures = config('bulk-imports.database.tables.failures', 'import_failures');

        Schema::connection($connection)->create($failures, function (Blueprint $table) use ($imports, $chunks): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_id')->constrained($imports)->cascadeOnDelete();
            $table->foreignUlid('import_chunk_id')->nullable()->constrained($chunks)->nullOnDelete();
            $table->unsignedBigInteger('row_number')->nullable();
            $table->string('column')->nullable();
            $table->string('error_code', 100)->index();
            $table->text('error_message');
            $table->json('original_row_data')->nullable();
            $table->boolean('redacted')->default(false);
            $table->timestamps();

            $table->index(['import_id', 'row_number']);
            $table->index(['import_id', 'import_chunk_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.failures', 'import_failures'));
    }
};
