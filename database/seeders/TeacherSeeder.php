<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Predefined faculty account for testing the teacher dashboard.
 *
 *   Faculty ID : 99-000001   (or email teacher@jru.edu.ph)
 *   Password   : Teacher123
 *   Role       : Faculty / Teacher
 *
 * Safe to run more than once: it updates the same account instead of duplicating it.
 * It refuses to run in production so a known password never ships to real users.
 */
class TeacherSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipped: the test teacher account is not created in production.');

            return;
        }

        User::updateOrCreate(
            ['email' => 'teacher@jru.edu.ph'],
            [
                'name' => 'Prof. Test Teacher',
                'password' => 'Teacher123', // hashed by the User model cast
                'role' => 'teacher',
                'faculty_id' => '99-000001',
                'student_number' => null,
                'department' => 'College of Computer Studies',
            ]
        );

        $this->command?->info('Test teacher ready: 99-000001 / teacher@jru.edu.ph  (password: Teacher123)');
    }
}
