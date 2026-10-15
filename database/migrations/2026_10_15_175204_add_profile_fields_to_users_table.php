
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student');
            $table->string('student_number')->nullable()->unique();
            $table->string('faculty_id')->nullable()->unique();
            $table->string('department')->nullable();
            $table->string('year_level')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_number']);
            $table->dropUnique(['faculty_id']);

            $table->dropColumn([
                'role',
                'student_number',
                'faculty_id',
                'department',
                'year_level',
            ]);
        });
    }
};
