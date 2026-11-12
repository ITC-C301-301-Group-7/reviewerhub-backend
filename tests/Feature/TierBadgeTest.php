<?php

namespace Tests\Feature;

use App\Models\LearningResource;
use App\Models\ResourceRating;
use App\Models\User;
use App\Support\ContributorTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TierBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $n): User
    {
        return User::create([
            'name' => "Student $n",
            'email' => "student$n@my.jru.edu",
            'password' => 'Password1',
            'role' => 'student',
            'student_number' => '25-10000' . $n,
        ]);
    }

    private function resource(User $owner, string $title = 'Reviewer'): LearningResource
    {
        return LearningResource::create([
            'user_id' => $owner->id,
            'title' => $title,
            'course_code' => 'TEST101',
            'original_filename' => 'file.pdf',
            'file_path' => 'reviewers/file.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
            'status' => 'approved',
        ]);
    }

    public function test_points_pick_the_tier(): void
    {
        $this->assertSame('tier0', ContributorTier::keyForPoints(0));
        $this->assertSame('tier0', ContributorTier::keyForPoints(49));
        $this->assertSame('tier1', ContributorTier::keyForPoints(50));
        $this->assertSame('tier1', ContributorTier::keyForPoints(99));
        $this->assertSame('tier2', ContributorTier::keyForPoints(100));
        $this->assertSame('tier5', ContributorTier::keyForPoints(450));
        $this->assertSame('tier10', ContributorTier::keyForPoints(900));
        $this->assertSame('tier10', ContributorTier::keyForPoints(1000));
    }

    public function test_a_new_student_gets_the_free_preview_but_no_downloads(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reviewers/file.pdf', '%PDF-1.4');

        $owner = $this->student('1');
        $newcomer = $this->student('2');
        $resource = $this->resource($owner);

        Sanctum::actingAs($newcomer);

        $this->getJson("/api/resources/{$resource->id}/access")
            ->assertOk()
            ->assertJsonPath('previewPages', 3)
            ->assertJsonPath('canDownload', false)
            ->assertJsonPath('tier.key', 'tier0');

        $this->get("/api/resources/{$resource->id}/download")->assertForbidden();
    }

    public function test_two_uploads_unlock_tier_2_downloads(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reviewers/file.pdf', '%PDF-1.4');

        $owner = $this->student('1');
        $resource = $this->resource($owner);

        // Two approved uploads = 100 points -> Tier 2.
        $reader = $this->student('2');
        $this->resource($reader, 'My upload 1');
        $this->resource($reader, 'My upload 2');

        Sanctum::actingAs($reader);

        $this->getJson('/api/tier')
            ->assertOk()
            ->assertJsonPath('key', 'tier2')
            ->assertJsonPath('points', 100)
            ->assertJsonPath('previewPages', 7);

        $this->get("/api/resources/{$resource->id}/download")->assertOk();
    }

    public function test_tier_1_gets_two_downloads_a_month(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reviewers/file.pdf', '%PDF-1.4');

        $owner = $this->student('1');
        $reader = $this->student('2');
        $this->resource($reader, 'My upload'); // 50 points -> Tier 1

        Sanctum::actingAs($reader);

        $this->getJson('/api/tier')
            ->assertOk()
            ->assertJsonPath('key', 'tier1')
            ->assertJsonPath('previewPages', 5)
            ->assertJsonPath('downloadsPerMonth', 2);

        $files = collect(range(1, 3))->map(fn($i) => $this->resource($owner, "Reviewer $i"));

        $this->get("/api/resources/{$files[0]->id}/download")->assertOk();
        $this->get("/api/resources/{$files[1]->id}/download")->assertOk();
        $this->get("/api/resources/{$files[2]->id}/download")->assertForbidden();
    }

    public function test_tier_2_monthly_download_limit_is_enforced(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reviewers/file.pdf', '%PDF-1.4');

        $owner = $this->student('1');
        $reader = $this->student('2');
        $this->resource($reader, 'My upload 1');
        $this->resource($reader, 'My upload 2'); // Tier 2: 3 downloads a month

        Sanctum::actingAs($reader);

        $files = collect(range(1, 4))->map(fn($i) => $this->resource($owner, "Reviewer $i"));

        foreach ($files->take(3) as $file) {
            $this->get("/api/resources/{$file->id}/download")->assertOk();
        }

        // Fourth different reviewer is over the allowance ...
        $this->get("/api/resources/{$files[3]->id}/download")->assertForbidden();

        // ... but a reviewer already downloaded this month can be downloaded again.
        $this->get("/api/resources/{$files[0]->id}/download")->assertOk();
    }

    public function test_five_star_ratings_add_to_the_tier_points(): void
    {
        $owner = $this->student('1');
        $resource = $this->resource($owner); // 50 points

        foreach (['2', '3', '4', '5', '6'] as $n) {
            ResourceRating::create([
                'user_id' => $this->student($n)->id,
                'learning_resource_id' => $resource->id,
                'rating' => 5,
            ]); // +10 each -> 100 points
        }

        $this->assertSame(100, ContributorTier::pointsFor($owner));
        $this->assertSame('tier2', ContributorTier::tierForPoints(100)['key']);
    }

    public function test_owners_are_never_limited(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reviewers/file.pdf', '%PDF-1.4');

        $owner = $this->student('1');
        $resource = $this->resource($owner);

        Sanctum::actingAs($owner);

        $this->getJson("/api/resources/{$resource->id}/access")
            ->assertOk()
            ->assertJsonPath('previewPages', null)
            ->assertJsonPath('canDownload', true);
    }
}
