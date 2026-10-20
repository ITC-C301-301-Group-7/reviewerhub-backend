<?php

namespace Database\Seeders;

use App\Models\LearningResource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ExistingReviewersSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            $this->command->error(
                'No user exists. Register a user first.'
            );
            return;
        }

        // Reads PDFs from storage/app/private/resources (the private disk).
        $files = Storage::disk('local')->files('resources');

        foreach ($files as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }

            $filename = basename($path);

            LearningResource::firstOrCreate(
                ['file_path' => $path],
                [
                    'user_id' => $user->id,
                    'title' => pathinfo($filename, PATHINFO_FILENAME),
                    'course_code' => 'GENERAL',
                    'college' => null,
                    'description' => 'Imported reviewer PDF.',
                    'original_filename' => $filename,
                    'mime_type' => 'application/pdf',
                    'file_size' => Storage::disk('local')->size($path),
                ]
            );
        }

        $this->command->info('Existing reviewer PDFs imported.');
    }
}
