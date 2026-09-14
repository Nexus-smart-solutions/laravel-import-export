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
        $rowKeys = config('bulk-imports.database.tables.row_keys', 'import_row_keys');

        Schema::connection($connection)->create($rowKeys, function (Blueprint $table) use ($imports, $chunks): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_id')->constrained($imports)->cascadeOnDelete();
            $table->foreignUlid('first_chunk_id')->nullable()->constrained($chunks)->nullOnDelete();
            $table->char('key_hash', 64);
            $table->unsignedBigInteger('first_row_number');
            $table->timestamps();

            $table->unique(['import_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.row_keys', 'import_row_keys'));
    }
};
