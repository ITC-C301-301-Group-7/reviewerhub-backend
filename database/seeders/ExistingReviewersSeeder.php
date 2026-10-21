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
        // Imported reviewers belong to the faculty account when there is one.
        $user = User::where('role', 'teacher')->first() ?? User::first();

        if (!$user) {
            $this->command->error('No user exists. Register a user first.');
            return;
        }

        $private = Storage::disk('local');   // storage/app/private (what preview/download read)
        $public = Storage::disk('public');   // storage/app/public  (where the PDFs were placed)

        // Copy any PDFs from the public folder into the private one, if missing.
        foreach ($public->files('resources') as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' && !$private->exists($path)) {
                $private->writeStream($path, $public->readStream($path));
            }
        }

        $imported = 0;

        foreach ($private->files('resources') as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }

            $filename = basename($path);

            $resource = LearningResource::firstOrCreate(
                ['file_path' => $path],
                [
                    'user_id' => $user->id,
                    'title' => pathinfo($filename, PATHINFO_FILENAME),
                    'course_code' => 'GENERAL',
                    'college' => null,
                    'description' => 'Imported reviewer PDF.',
                    'original_filename' => $filename,
                    'mime_type' => 'application/pdf',
                    'file_size' => $private->size($path),
                ]
            );

            if ($resource->wasRecentlyCreated) {
                $imported++;
            }
        }

        $this->command->info("Reviewer PDFs ready ({$imported} newly imported).");
    }
}
