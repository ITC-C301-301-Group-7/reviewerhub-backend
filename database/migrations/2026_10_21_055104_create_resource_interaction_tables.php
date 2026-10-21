<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved-for-later reviewers (one row per user + reviewer).
        Schema::create('resource_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_resource_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'learning_resource_id']);
        });

        // 1-5 star usefulness rating (one per user + reviewer, can be changed).
        Schema::create('resource_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'learning_resource_id']);
        });

        Schema::create('resource_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_resource_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['learning_resource_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_comments');
        Schema::dropIfExists('resource_ratings');
        Schema::dropIfExists('resource_bookmarks');
    }
};
