<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A student can report a reviewer once. Reports earn "points_per_report" points (with a daily cap).
        Schema::create('resource_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_resource_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 30);
            $table->text('details')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'learning_resource_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_reports');
    }
};
