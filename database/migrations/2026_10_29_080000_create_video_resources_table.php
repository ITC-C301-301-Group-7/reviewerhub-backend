<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Video reviewers shared as YouTube / Vimeo links (the video itself is not stored here).
        Schema::create('video_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('course_code', 50);
            $table->string('college')->nullable();
            $table->text('description')->nullable();
            $table->string('url', 500);
            $table->string('provider', 20);   // youtube | vimeo
            $table->string('video_id', 50);   // the provider's own id, used to build the embed
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_resources');
    }
};
