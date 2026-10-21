<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_resources', function (Blueprint $table) {
            // approved = visible in the community, hidden = removed from the community by a teacher.
            // Existing and new uploads stay visible by default so the student flow is unchanged.
            $table->string('status')->default('approved')->index();
            $table->text('moderation_note')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learning_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['status', 'moderation_note', 'moderated_at']);
        });
    }
};
