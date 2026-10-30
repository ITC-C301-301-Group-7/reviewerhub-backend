<?php

namespace App\Support;

use App\Models\LearningResource;
use App\Models\ResourceBookmark;
use App\Models\ResourceRating;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * One place that turns a LearningResource into the JSON the React app expects,
 * including rating / comment / bookmark information for the signed-in user.
 */
class ResourcePresenter
{
    // Adds the counts and averages the presenter needs.
    public static function withStats(Builder $query): Builder
    {
        return $query
            ->with('user:id,name,role,department')
            ->withAvg('ratings', 'rating')
            ->withCount(['ratings', 'comments', 'bookmarks']);
    }

    public static function paginate(Builder $query, int $perPage, ?User $viewer): LengthAwarePaginator
    {
        $page = self::withStats($query)->paginate($perPage);
        $ids = $page->getCollection()->pluck('id')->all();

        $bookmarked = $viewer
            ? ResourceBookmark::where('user_id', $viewer->id)
            ->whereIn('learning_resource_id', $ids)
            ->pluck('learning_resource_id')->all()
            : [];

        $myRatings = $viewer
            ? ResourceRating::where('user_id', $viewer->id)
            ->whereIn('learning_resource_id', $ids)
            ->pluck('rating', 'learning_resource_id')->all()
            : [];

        return $page->through(
            fn($r) => self::present($r, in_array($r->id, $bookmarked), $myRatings[$r->id] ?? null)
        );
    }

    // The first $limit rows of an already-ordered query, in the same JSON shape as paginate().
    public static function top(Builder $query, int $limit, ?User $viewer): array
    {
        $items = self::withStats($query)->limit($limit)->get();
        $ids = $items->pluck('id')->all();

        $bookmarked = $viewer
            ? ResourceBookmark::where('user_id', $viewer->id)
            ->whereIn('learning_resource_id', $ids)
            ->pluck('learning_resource_id')->all()
            : [];

        $myRatings = $viewer
            ? ResourceRating::where('user_id', $viewer->id)
            ->whereIn('learning_resource_id', $ids)
            ->pluck('rating', 'learning_resource_id')->all()
            : [];

        return $items
            ->map(fn($r) => self::present($r, in_array($r->id, $bookmarked), $myRatings[$r->id] ?? null))
            ->values()
            ->all();
    }

    // For a single resource (loads the stats itself if they are missing).
    public static function presentFor(LearningResource $resource, ?User $viewer): array
    {
        $resource->loadMissing('user:id,name,role,department')
            ->loadAvg('ratings', 'rating')
            ->loadCount(['ratings', 'comments', 'bookmarks']);

        $bookmarked = $viewer
            && ResourceBookmark::where('user_id', $viewer->id)
            ->where('learning_resource_id', $resource->id)->exists();

        $myRating = $viewer
            ? ResourceRating::where('user_id', $viewer->id)
            ->where('learning_resource_id', $resource->id)->value('rating')
            : null;

        return self::present($resource, (bool) $bookmarked, $myRating);
    }

    public static function present(LearningResource $r, bool $bookmarked = false, ?int $myRating = null): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'courseCode' => $r->course_code,
            'college' => $r->college,
            'description' => $r->description,
            'author' => $r->user?->name ?? 'ReviewerHub User',
            'userId' => $r->user_id,
            // Official = published by a faculty account (shown in Reviewer Community > Official Learning Resources).
            'official' => $r->user?->role === 'teacher',
            'authorDepartment' => $r->user?->department,
            'createdAt' => $r->created_at?->toIso8601String(),
            'filename' => $r->original_filename,
            'mimeType' => $r->mime_type,
            'fileSize' => $r->file_size,
            'previewUrl' => url("/api/resources/{$r->id}/preview"),
            'downloadUrl' => url("/api/resources/{$r->id}/download"),
            'ratingAverage' => $r->ratings_avg_rating !== null
                ? round((float) $r->ratings_avg_rating, 1)
                : null,
            'ratingCount' => (int) ($r->ratings_count ?? 0),
            'commentCount' => (int) ($r->comments_count ?? 0),
            'bookmarkCount' => (int) ($r->bookmarks_count ?? 0),
            'bookmarked' => $bookmarked,
            'myRating' => $myRating,
        ];
    }
}
