<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        foreach (['imports', 'chunks'] as $key) {
            $schema->table(config('bulk-imports.database.tables.'.$key), function (Blueprint $table) use ($key): void {
                foreach (['inserted_rows', 'updated_rows', 'would_insert', 'would_update'] as $column) {
                    $table->unsignedBigInteger($column)->default(0);
                }
                if ($key === 'imports') {
                    $table->unsignedInteger('processed_chunks')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        foreach (['imports', 'chunks'] as $key) {
            $schema->table(config('bulk-imports.database.tables.'.$key), function (Blueprint $table) use ($key): void {
                $table->dropColumn(['inserted_rows', 'updated_rows', 'would_insert', 'would_update']);
                if ($key === 'imports') {
                    $table->dropColumn('processed_chunks');
                }
            });
        }
    }
};
