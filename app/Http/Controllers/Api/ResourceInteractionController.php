<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Models\ResourceBookmark;
use App\Models\ResourceComment;
use App\Models\ResourceRating;
use App\Support\ResourcePresenter;
use Illuminate\Http\Request;

/**
 * Resource interaction: open one resource (QR deep links), bookmarks, ratings, comments.
 * Every route here is behind auth:sanctum.
 */
class ResourceInteractionController extends Controller
{
    // GET /api/resources/{resource}  (used when a QR code / shared link is opened)
    public function show(Request $request, LearningResource $resource)
    {
        $this->authorizeView($request, $resource);

        return response()->json([
            'resource' => ResourcePresenter::presentFor($resource, $request->user()),
        ]);
    }

    /* ---------------- Bookmarks ---------------- */

    // GET /api/bookmarks
    public function bookmarks(Request $request)
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();

        // Newest bookmark first. Hidden reviewers drop out of the list.
        $query = LearningResource::query()
            ->where('status', 'approved')
            ->join('resource_bookmarks', 'resource_bookmarks.learning_resource_id', '=', 'learning_resources.id')
            ->where('resource_bookmarks.user_id', $user->id)
            ->orderByDesc('resource_bookmarks.created_at')
            ->orderByDesc('resource_bookmarks.id')
            ->select('learning_resources.*');

        return response()->json(
            ResourcePresenter::paginate($query, (int) $request->query('per_page', 12), $user)
        );
    }

    // PUT /api/resources/{resource}/bookmark  (idempotent)
    public function addBookmark(Request $request, LearningResource $resource)
    {
        $this->authorizeView($request, $resource);

        ResourceBookmark::firstOrCreate([
            'user_id' => $request->user()->id,
            'learning_resource_id' => $resource->id,
        ]);

        return response()->json(['bookmarked' => true]);
    }

    // DELETE /api/resources/{resource}/bookmark  (idempotent)
    public function removeBookmark(Request $request, LearningResource $resource)
    {
        ResourceBookmark::where('user_id', $request->user()->id)
            ->where('learning_resource_id', $resource->id)
            ->delete();

        return response()->json(['bookmarked' => false]);
    }

    /* ---------------- Ratings ---------------- */

    // PUT /api/resources/{resource}/rating  { rating: 1-5 }
    public function rate(Request $request, LearningResource $resource)
    {
        $this->authorizeView($request, $resource);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
        ]);

        abort_if(
            $request->user()->id === $resource->user_id,
            403,
            'You cannot rate your own upload.'
        );

        ResourceRating::updateOrCreate(
            ['user_id' => $request->user()->id, 'learning_resource_id' => $resource->id],
            ['rating' => $validated['rating']]
        );

        $stats = $resource->ratings()->selectRaw('AVG(rating) as average, COUNT(*) as total')->first();

        return response()->json([
            'myRating' => (int) $validated['rating'],
            'ratingAverage' => round((float) $stats->average, 1),
            'ratingCount' => (int) $stats->total,
        ]);
    }

    /* ---------------- Comments ---------------- */

    // GET /api/resources/{resource}/comments
    public function comments(Request $request, LearningResource $resource)
    {
        $this->authorizeView($request, $resource);

        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $viewer = $request->user();

        return response()->json(
            $resource->comments()
                ->with('user:id,name,role')
                ->latest()
                ->orderByDesc('id')
                ->paginate(10)
                ->through(fn($c) => $this->presentComment($c, $viewer))
        );
    }

    // POST /api/resources/{resource}/comments  { body }
    public function storeComment(Request $request, LearningResource $resource)
    {
        $this->authorizeView($request, $resource);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $comment = $resource->comments()->create([
            'user_id' => $request->user()->id,
            'body' => trim($validated['body']),
        ]);

        return response()->json([
            'message' => 'Comment posted.',
            'comment' => $this->presentComment($comment->load('user:id,name,role'), $request->user()),
            'commentCount' => $resource->comments()->count(),
        ], 201);
    }

    // DELETE /api/comments/{comment}  (the author or any teacher)
    public function destroyComment(Request $request, ResourceComment $comment)
    {
        abort_unless(
            $request->user()->id === $comment->user_id || $request->user()->role === 'teacher',
            403,
            'You can only delete your own comments.'
        );

        $resourceId = $comment->learning_resource_id;
        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted.',
            'commentCount' => ResourceComment::where('learning_resource_id', $resourceId)->count(),
        ]);
    }

    private function authorizeView(Request $request, LearningResource $resource): void
    {
        abort_unless(
            $resource->isViewableBy($request->user()),
            404,
            'This reviewer is no longer available.'
        );
    }

    private function presentComment(ResourceComment $c, $viewer): array
    {
        return [
            'id' => $c->id,
            'body' => $c->body,
            'author' => $c->user?->name ?? 'ReviewerHub User',
            'authorRole' => $c->user?->role,
            'userId' => $c->user_id,
            'createdAt' => $c->created_at?->toIso8601String(),
            'canDelete' => $viewer && ($viewer->id === $c->user_id || $viewer->role === 'teacher'),
        ];
    }
}
