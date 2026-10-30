<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Models\ResourceBookmark;
use App\Models\ResourceComment;
use App\Models\ResourceRating;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Faculty-only endpoints (routes are wrapped in the `teacher` middleware).
 * Uploading and deleting reviewers reuse the shared /api/resources endpoints.
 */
class TeacherController extends Controller
{
    // A reviewer with at least this many ratings and an average at or below
    // LOW_RATING_MAX shows up in the "Needs attention" list on the overview.
    private const LOW_RATING_MIN_VOTES = 2;
    private const LOW_RATING_MAX = 2.5;

    // GET /api/teacher/overview
    public function overview(Request $request)
    {
        $weekAgo = now()->subDays(7);
        $userId = $request->user()->id;

        // One query for every reviewer counter (was 6 separate COUNT queries).
        $resourceStats = LearningResource::query()
            ->selectRaw(
                "COUNT(*) as total,
                 COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) as visible,
                 COALESCE(SUM(CASE WHEN status = 'hidden' THEN 1 ELSE 0 END), 0) as hidden,
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as new_week,
                 COALESCE(SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END), 0) as mine",
                [$weekAgo, $userId]
            )
            ->first();

        // One query for every member counter.
        $userStats = User::query()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END), 0) as students,
                 COALESCE(SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END), 0) as teachers,
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as new_week",
                [$weekAgo]
            )
            ->first();

        // Uploads per day for the last 7 days (oldest first). Grouped in the
        // database instead of loading every row into PHP.
        $perDay = LearningResource::query()
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $activity = collect(range(6, 0))->map(function ($daysAgo) use ($perDay) {
            $date = now()->subDays($daysAgo);

            return [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('D'),
                'uploads' => (int) ($perDay[$date->format('Y-m-d')] ?? 0),
            ];
        })->values();

        $topContributors = User::query()
            ->withCount([
                'learningResources as uploads_count' => fn($q) => $q->where('status', 'approved'),
            ])
            ->where('role', 'student')
            ->having('uploads_count', '>', 0)
            ->orderByDesc('uploads_count')
            ->limit(5)
            ->get(['id', 'name', 'department'])
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'department' => $u->department,
                'uploads' => $u->uploads_count,
            ]);

        // Visible reviewers per college, biggest first.
        $byCollege = LearningResource::query()
            ->where('status', 'approved')
            ->whereNotNull('college')
            ->where('college', '!=', '')
            ->selectRaw('college, COUNT(*) as total')
            ->groupBy('college')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn($row) => ['college' => $row->college, 'total' => (int) $row->total])
            ->values();

        // Visible reviewers that students rate poorly: worth a teacher's look.
        $lowRated = LearningResource::query()
            ->with('user:id,name,role,student_number,department')
            ->withAvg('ratings', 'rating')
            ->withCount(['ratings', 'comments', 'bookmarks'])
            ->where('status', 'approved')
            ->having('ratings_count', '>=', self::LOW_RATING_MIN_VOTES)
            ->having('ratings_avg_rating', '<=', self::LOW_RATING_MAX)
            ->orderBy('ratings_avg_rating')
            ->limit(5)
            ->get()
            ->map(fn($r) => $this->present($r));

        $recent = LearningResource::with('user:id,name,role,student_number,department')
            ->withAvg('ratings', 'rating')
            ->withCount(['ratings', 'comments', 'bookmarks'])
            ->latest()
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn($r) => $this->present($r));

        $avgRating = ResourceRating::avg('rating');

        return response()->json([
            'stats' => [
                'totalReviewers' => (int) $resourceStats->total,
                'visibleReviewers' => (int) $resourceStats->visible,
                'hiddenReviewers' => (int) $resourceStats->hidden,
                'studentSubmissions' => LearningResource::whereHas('user', fn($q) => $q->where('role', 'student'))->count(),
                'myUploads' => (int) $resourceStats->mine,
                'newThisWeek' => (int) $resourceStats->new_week,
                'students' => (int) $userStats->students,
                'teachers' => (int) $userStats->teachers,
                'newMembersThisWeek' => (int) $userStats->new_week,
                // Engagement across the whole community.
                'avgRating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
                'totalRatings' => ResourceRating::count(),
                'totalComments' => ResourceComment::count(),
                'totalBookmarks' => ResourceBookmark::count(),
            ],
            'activity' => $activity,
            'topContributors' => $topContributors,
            'byCollege' => $byCollege,
            'lowRated' => $lowRated,
            'recent' => $recent,
        ]);
    }

    // GET /api/teacher/resources
    //   ?author=all|student|mine &status=all|approved|hidden &college= &period=all|week
    //   &sort=newest|oldest|rating|popular|title &search= &page= &per_page=
    public function resources(Request $request)
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'author' => ['sometimes', Rule::in(['all', 'student', 'mine'])],
            'status' => ['sometimes', Rule::in(['all', 'approved', 'hidden'])],
            'college' => ['sometimes', 'nullable', 'string', 'max:255'],
            'period' => ['sometimes', Rule::in(['all', 'week'])],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest', 'rating', 'popular', 'title'])],
        ]);

        $query = LearningResource::with('user:id,name,role,student_number,department')
            ->withAvg('ratings', 'rating')
            ->withCount(['ratings', 'comments', 'bookmarks']);

        $author = $filters['author'] ?? 'all';
        if ($author === 'student') {
            $query->whereHas('user', fn($q) => $q->where('role', 'student'));
        } elseif ($author === 'mine') {
            $query->where('user_id', $request->user()->id);
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['college'])) {
            $query->where('college', $filters['college']);
        }

        if (($filters['period'] ?? 'all') === 'week') {
            $query->where('created_at', '>=', now()->subDays(7));
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('course_code', 'like', $term)
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', $term));
            });
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'title' => $query->orderBy('title')->orderBy('id'),
            // Reviewers without ratings sink to the bottom.
            'rating' => $query->orderByDesc('ratings_avg_rating')->orderByDesc('ratings_count')->orderByDesc('id'),
            'popular' => $query->orderByDesc('bookmarks_count')->orderByDesc('comments_count')->orderByDesc('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return response()->json(
            $query
                ->paginate($filters['per_page'] ?? 10)
                ->through(fn($r) => $this->present($r))
        );
    }

    // PATCH /api/teacher/resources/{resource}
    public function update(Request $request, LearningResource $resource)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:50'],
            'college' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['course_code'] = strtoupper($validated['course_code']);
        $resource->update($validated);

        return response()->json([
            'message' => 'Reviewer updated.',
            'resource' => $this->present($this->withStats($resource)),
        ]);
    }

    // PATCH /api/teacher/resources/{resource}/status
    public function setStatus(Request $request, LearningResource $resource)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'hidden'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $resource->update($this->moderationFields($request, $validated));

        return response()->json([
            'message' => $validated['status'] === 'hidden'
                ? 'Reviewer hidden from the community.'
                : 'Reviewer is visible in the community again.',
            'resource' => $this->present($this->withStats($resource)),
        ]);
    }

    // PATCH /api/teacher/resources/bulk-status  { ids: [..], status, note? }
    // Hides or restores several reviewers in one request (max 50).
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::in(['approved', 'hidden'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = LearningResource::whereIn('id', $validated['ids'])
            ->update($this->moderationFields($request, $validated));

        $noun = $updated === 1 ? 'reviewer' : 'reviewers';

        return response()->json([
            'message' => $validated['status'] === 'hidden'
                ? "{$updated} {$noun} hidden from the community."
                : "{$updated} {$noun} visible in the community again.",
            'updated' => $updated,
        ]);
    }

    // GET /api/teacher/community?role=all|student|teacher&search=&page=
    public function community(Request $request)
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', Rule::in(['all', 'student', 'teacher'])],
        ]);

        $query = User::query()
            ->withCount('learningResources as uploads_count')
            ->latest()
            ->orderByDesc('id');

        if (($filters['role'] ?? 'all') !== 'all') {
            $query->where('role', $filters['role']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhere('faculty_id', 'like', $term);
            });
        }

        return response()->json(
            $query
                ->paginate($filters['per_page'] ?? 10)
                ->through(fn($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'schoolId' => $u->role === 'teacher' ? $u->faculty_id : $u->student_number,
                    'department' => $u->department,
                    'yearLevel' => $u->year_level,
                    'uploads' => $u->uploads_count,
                    'joinedAt' => $u->created_at?->toIso8601String(),
                ])
        );
    }

    // The columns that change when a teacher hides or restores a reviewer.
    private function moderationFields(Request $request, array $validated): array
    {
        return [
            'status' => $validated['status'],
            'moderation_note' => $validated['status'] === 'hidden' ? ($validated['note'] ?? null) : null,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ];
    }

    // Reloads a resource with its author and engagement numbers for present().
    private function withStats(LearningResource $resource): LearningResource
    {
        return $resource
            ->load('user:id,name,role,student_number,department')
            ->loadAvg('ratings', 'rating')
            ->loadCount(['ratings', 'comments', 'bookmarks']);
    }

    private function present(LearningResource $r): array
    {
        $avg = $r->ratings_avg_rating;

        return [
            'id' => $r->id,
            'title' => $r->title,
            'courseCode' => $r->course_code,
            'college' => $r->college,
            'description' => $r->description,
            'author' => $r->user?->name ?? 'ReviewerHub User',
            'authorRole' => $r->user?->role,
            'authorId' => $r->user?->role === 'teacher'
                ? null
                : $r->user?->student_number,
            'userId' => $r->user_id,
            'status' => $r->status,
            'moderationNote' => $r->moderation_note,
            'moderatedAt' => $r->moderated_at?->toIso8601String(),
            'createdAt' => $r->created_at?->toIso8601String(),
            'filename' => $r->original_filename,
            'mimeType' => $r->mime_type,
            'fileSize' => $r->file_size,
            'ratingAvg' => $avg !== null ? round((float) $avg, 1) : null,
            'ratingCount' => (int) ($r->ratings_count ?? 0),
            'commentCount' => (int) ($r->comments_count ?? 0),
            'bookmarkCount' => (int) ($r->bookmarks_count ?? 0),
            'previewUrl' => url("/api/resources/{$r->id}/preview"),
            'downloadUrl' => url("/api/resources/{$r->id}/download"),
        ];
    }
}
