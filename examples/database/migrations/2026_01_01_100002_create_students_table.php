<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('student_code', 50)->unique();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
