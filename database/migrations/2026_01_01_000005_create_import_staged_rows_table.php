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
        $staged = config('bulk-imports.database.tables.staged_rows', 'import_staged_rows');

        Schema::connection($connection)->create($staged, function (Blueprint $table) use ($imports, $chunks): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_id')->constrained($imports)->cascadeOnDelete();
            $table->foreignUlid('import_chunk_id')->constrained($chunks)->cascadeOnDelete();
            $table->unsignedBigInteger('row_number');
            $table->char('business_key_hash', 64)->nullable();
            $table->json('source_data');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'business_key_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.staged_rows', 'import_staged_rows'));
    }
};
