<?php

namespace Tests\Feature;

use App\Models\LearningResource;
use App\Models\ResourceBookmark;
use App\Models\ResourceComment;
use App\Models\ResourceLike;
use App\Models\ResourceRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopReviewersTest extends TestCase
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

    private function resource(User $owner, string $title, string $status = 'approved'): LearningResource
    {
        return LearningResource::create([
            'user_id' => $owner->id,
            'title' => $title,
            'course_code' => 'TEST101',
            'original_filename' => 'file.pdf',
            'file_path' => 'reviewers/file.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
            'status' => $status,
        ]);
    }

    public function test_guests_cannot_see_the_top_list(): void
    {
        $this->getJson('/api/resources/top')->assertUnauthorized();
    }

    public function test_invalid_sort_is_rejected(): void
    {
        Sanctum::actingAs($this->student('1'));

        $this->getJson('/api/resources/top?sort=banana')->assertStatus(422);
    }

    public function test_top_rated_orders_by_average_rating(): void
    {
        $owner = $this->student('1');
        [$a, $b, $c] = [$this->student('2'), $this->student('3'), $this->student('4')];
        $low = $this->resource($owner, 'Low rated');
        $high = $this->resource($owner, 'High rated');
        $this->resource($owner, 'Never rated');

        foreach ([[$low, 2], [$high, 5]] as [$r, $stars]) {
            foreach ([$a, $b] as $u) {
                ResourceRating::create(['user_id' => $u->id, 'learning_resource_id' => $r->id, 'rating' => $stars]);
            }
        }

        Sanctum::actingAs($c);

        $titles = collect($this->getJson('/api/resources/top?sort=rated')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['High rated', 'Low rated'], $titles); // unrated reviewers are left out
    }

    public function test_most_reviewed_counts_ratings_and_comments(): void
    {
        $owner = $this->student('1');
        $a = $this->student('2');
        $b = $this->student('3');
        $quiet = $this->resource($owner, 'Quiet');
        $busy = $this->resource($owner, 'Busy');

        ResourceRating::create(['user_id' => $a->id, 'learning_resource_id' => $quiet->id, 'rating' => 5]);
        ResourceRating::create(['user_id' => $a->id, 'learning_resource_id' => $busy->id, 'rating' => 3]);
        ResourceComment::create(['user_id' => $a->id, 'learning_resource_id' => $busy->id, 'body' => 'Helpful']);
        ResourceComment::create(['user_id' => $b->id, 'learning_resource_id' => $busy->id, 'body' => 'Thanks']);

        Sanctum::actingAs($b);

        $titles = collect($this->getJson('/api/resources/top?sort=reviewed')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['Busy', 'Quiet'], $titles);
    }

    public function test_most_liked_orders_by_likes_and_skips_unliked(): void
    {
        $owner = $this->student('1');
        $a = $this->student('2');
        $b = $this->student('3');
        $some = $this->resource($owner, 'Some likes');
        $many = $this->resource($owner, 'Many likes');
        $this->resource($owner, 'No likes');

        ResourceLike::create(['user_id' => $a->id, 'learning_resource_id' => $some->id]);
        ResourceLike::create(['user_id' => $a->id, 'learning_resource_id' => $many->id]);
        ResourceLike::create(['user_id' => $b->id, 'learning_resource_id' => $many->id]);

        Sanctum::actingAs($a);

        $data = $this->getJson('/api/resources/top?sort=liked')->assertOk()->json('data');

        $this->assertSame(['Many likes', 'Some likes'], collect($data)->pluck('title')->all());
        $this->assertSame(2, $data[0]['likeCount']);
        $this->assertTrue($data[0]['liked']);
    }

    public function test_like_and_unlike_are_idempotent_and_owner_cannot_like(): void
    {
        $owner = $this->student('1');
        $fan = $this->student('2');
        $r = $this->resource($owner, 'Liked');

        Sanctum::actingAs($fan);
        $this->putJson("/api/resources/{$r->id}/like")->assertOk()->assertJson(['liked' => true, 'likeCount' => 1]);
        $this->putJson("/api/resources/{$r->id}/like")->assertOk()->assertJson(['likeCount' => 1]);
        $this->deleteJson("/api/resources/{$r->id}/like")->assertOk()->assertJson(['liked' => false, 'likeCount' => 0]);

        Sanctum::actingAs($owner);
        $this->putJson("/api/resources/{$r->id}/like")->assertForbidden();
    }

    public function test_most_saved_and_hidden_reviewers_are_excluded(): void
    {
        $owner = $this->student('1');
        $a = $this->student('2');
        $b = $this->student('3');
        $popular = $this->resource($owner, 'Popular');
        $hidden = $this->resource($owner, 'Hidden', 'hidden');

        foreach ([$a, $b] as $u) {
            ResourceBookmark::create(['user_id' => $u->id, 'learning_resource_id' => $popular->id]);
            ResourceBookmark::create(['user_id' => $u->id, 'learning_resource_id' => $hidden->id]);
        }

        Sanctum::actingAs($a);

        $data = $this->getJson('/api/resources/top?sort=saved')->assertOk()->json('data');

        $this->assertSame(['Popular'], collect($data)->pluck('title')->all());
        $this->assertSame(2, $data[0]['bookmarkCount']);
    }
}
