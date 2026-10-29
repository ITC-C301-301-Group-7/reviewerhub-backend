<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoResource;
use App\Support\VideoLink;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Video reviewers shared as YouTube / Vimeo links. Every route is behind auth:sanctum.
 */
class VideoResourceController extends Controller
{
    // GET /api/videos?page=&per_page=&search=&college=
    public function index(Request $request)
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'college' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Newest first; id breaks ties so pages never repeat or skip rows.
        $query = VideoResource::query()->with('user:id,name')->latest()->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('course_code', 'like', $term)
                    ->orWhereHas('user', fn($user) => $user->where('name', 'like', $term));
            });
        }

        if (!empty($filters['college'])) {
            $query->where('college', 'like', '%' . $filters['college'] . '%');
        }

        $viewer = $request->user();
        $page = $query->paginate($filters['per_page'] ?? 6);

        return response()->json($page->through(fn($video) => $this->present($video, $viewer)));
    }

    // POST /api/videos
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:50'],
            'college' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'url' => ['required', 'string', 'max:500'],
        ]);

        $link = VideoLink::parse($validated['url']);

        if (!$link) {
            throw ValidationException::withMessages([
                'url' => 'Paste a YouTube or Vimeo video link.',
            ]);
        }

        $video = VideoResource::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'course_code' => strtoupper($validated['course_code']),
            'college' => $validated['college'] ?? null,
            'description' => $validated['description'] ?? null,
            'url' => trim($validated['url']),
            'provider' => $link['provider'],
            'video_id' => $link['id'],
        ]);

        return response()->json([
            'video' => $this->present($video->load('user:id,name'), $request->user()),
        ], 201);
    }

    // DELETE /api/videos/{video}  (the person who shared it, or a teacher)
    public function destroy(Request $request, VideoResource $video)
    {
        abort_unless($this->canDelete($video, $request->user()), 403, 'You can only remove videos you shared.');

        $video->delete();

        return response()->json(['deleted' => true]);
    }

    private function canDelete(VideoResource $video, ?User $viewer): bool
    {
        return $viewer !== null && ($viewer->role === 'teacher' || $viewer->id === $video->user_id);
    }

    private function present(VideoResource $video, ?User $viewer): array
    {
        return [
            'id' => $video->id,
            'title' => $video->title,
            'courseCode' => $video->course_code,
            'college' => $video->college,
            'description' => $video->description,
            'author' => $video->user?->name ?? 'ReviewerHub User',
            'userId' => $video->user_id,
            'createdAt' => $video->created_at?->toIso8601String(),
            'url' => $video->url,
            'provider' => $video->provider,
            'embedUrl' => VideoLink::embedUrl($video->provider, $video->video_id),
            'thumbnailUrl' => VideoLink::thumbnailUrl($video->provider, $video->video_id),
            'canDelete' => $this->canDelete($video, $viewer),
        ];
    }
}
