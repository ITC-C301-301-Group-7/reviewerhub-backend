<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Predefined faculty account for testing the teacher dashboard.
        $this->call(TeacherSeeder::class);

        // 2. Sample student (only created once).
        if (!User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // 3. Import the reviewer PDFs (assigned to the teacher account).
        $this->call(ExistingReviewersSeeder::class);
    }
}
