<?php

// Illustrative host schema. Copy/adapt intentionally; the package never loads this migration.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['schools', 'classrooms', 'countries', 'categories', 'brands', 'departments'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name');
                $table->timestamps();
            });
        }
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('student_code', 50)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('status', 30)->index();
            $table->foreignId('school_id')->constrained();
            $table->foreignId('classroom_id')->nullable()->constrained();
            $table->foreignId('country_id')->nullable()->constrained();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->string('status', 30);
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_code', 50)->unique();
            $table->string('name');
            $table->foreignId('department_id')->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['students', 'products', 'employees', 'schools', 'classrooms', 'countries', 'categories', 'brands', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
