<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per student per day. Each row is worth "points_per_checkin" points.
        Schema::create('daily_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checkin_date');
            $table->timestamps();

            $table->unique(['user_id', 'checkin_date']);
            $table->index('checkin_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checkins');
    }
};
