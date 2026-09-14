<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->table(config('bulk-imports.database.tables.failures'), function (Blueprint $table): void {
            $table->string('sheet')->nullable();
            $table->unsignedBigInteger('sheet_row')->nullable();
            $table->string('failure_type', 32)->default('validation_error');
            $table->json('normalized_row_data')->nullable();
        });
        $schema->table(config('bulk-imports.database.tables.imports'), function (Blueprint $table): void {
            $table->string('failure_type', 32)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('bulk-imports.database.connection'));
        $schema->table(config('bulk-imports.database.tables.failures'), function (Blueprint $table): void {
            $table->dropColumn(['sheet', 'sheet_row', 'failure_type', 'normalized_row_data']);
        });
        $schema->table(config('bulk-imports.database.tables.imports'), function (Blueprint $table): void {
            $table->dropColumn('failure_type');
        });
    }
};
