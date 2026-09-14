<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('bulk-imports.database.connection');
        $tableName = config('bulk-imports.database.tables.key_locks', 'import_key_locks');

        Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
            $table->char('scope_hash', 64);
            $table->char('key_hash', 64);
            $table->ulid('owner_import_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['scope_hash', 'key_hash']);
            $table->index('owner_import_id');
        });
    }

    public function down(): void
    {
        Schema::connection(config('bulk-imports.database.connection'))
            ->dropIfExists(config('bulk-imports.database.tables.key_locks', 'import_key_locks'));
    }
};
