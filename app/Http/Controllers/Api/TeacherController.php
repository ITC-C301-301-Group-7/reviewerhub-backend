<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Faculty-only endpoints (routes are wrapped in the `teacher` middleware).
 * Uploading and deleting reviewers reuse the shared /api/resources endpoints.
 */
class TeacherController extends Controller
{
    // GET /api/teacher/overview
    public function overview()
    {
        $weekAgo = now()->subDays(7);

        // Uploads per day for the last 7 days (oldest first) for the activity chart.
        $perDay = LearningResource::where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn($r) => $r->created_at->format('Y-m-d'))
            ->map->count();

        $activity = collect(range(6, 0))->map(function ($daysAgo) use ($perDay) {
            $date = now()->subDays($daysAgo);

            return [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('D'),
                'uploads' => $perDay[$date->format('Y-m-d')] ?? 0,
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

        $recent = LearningResource::with('user:id,name,role')
            ->latest()
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn($r) => $this->present($r));

        return response()->json([
            'stats' => [
                'totalReviewers' => LearningResource::count(),
                'visibleReviewers' => LearningResource::where('status', 'approved')->count(),
                'hiddenReviewers' => LearningResource::where('status', 'hidden')->count(),
                'studentSubmissions' => LearningResource::whereHas('user', fn($q) => $q->where('role', 'student'))->count(),
                'myUploads' => LearningResource::where('user_id', request()->user()->id)->count(),
                'newThisWeek' => LearningResource::where('created_at', '>=', $weekAgo)->count(),
                'students' => User::where('role', 'student')->count(),
                'teachers' => User::where('role', 'teacher')->count(),
                'newMembersThisWeek' => User::where('created_at', '>=', $weekAgo)->count(),
            ],
            'activity' => $activity,
            'topContributors' => $topContributors,
            'recent' => $recent,
        ]);
    }

    // GET /api/teacher/resources?author=all|student|mine&status=all|approved|hidden&search=&page=
    public function resources(Request $request)
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'author' => ['sometimes', Rule::in(['all', 'student', 'mine'])],
            'status' => ['sometimes', Rule::in(['all', 'approved', 'hidden'])],
        ]);

        $query = LearningResource::with('user:id,name,role,student_number,department')
            ->latest()
            ->orderByDesc('id');

        $author = $filters['author'] ?? 'all';
        if ($author === 'student') {
            $query->whereHas('user', fn($q) => $q->where('role', 'student'));
        } elseif ($author === 'mine') {
            $query->where('user_id', $request->user()->id);
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('course_code', 'like', $term)
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', $term));
            });
        }

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
            'resource' => $this->present($resource->load('user:id,name,role,student_number,department')),
        ]);
    }

    // PATCH /api/teacher/resources/{resource}/status
    public function setStatus(Request $request, LearningResource $resource)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'hidden'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $resource->update([
            'status' => $validated['status'],
            'moderation_note' => $validated['status'] === 'hidden' ? ($validated['note'] ?? null) : null,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);

        return response()->json([
            'message' => $validated['status'] === 'hidden'
                ? 'Reviewer hidden from the community.'
                : 'Reviewer is visible in the community again.',
            'resource' => $this->present($resource->load('user:id,name,role,student_number,department')),
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

    private function present(LearningResource $r): array
    {
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
            'previewUrl' => url("/api/resources/{$r->id}/preview"),
            'downloadUrl' => url("/api/resources/{$r->id}/download"),
        ];
    }
}
