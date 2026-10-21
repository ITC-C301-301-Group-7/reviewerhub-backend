<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per class on a student's personal schedule.
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('instructor')->nullable();
            $table->string('schedule');          // free text, e.g. "Mon/Wed 10:00 AM - 11:30 AM"
            $table->string('room')->nullable();
            $table->string('grade', 10)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
