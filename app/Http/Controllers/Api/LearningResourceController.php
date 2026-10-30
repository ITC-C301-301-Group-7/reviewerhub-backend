<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Support\ResourcePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LearningResourceController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'college' => ['sometimes', 'nullable', 'string', 'max:255'],
            'official' => ['sometimes', 'boolean'],
        ]);

        // Newest first; id breaks ties so pages never repeat or skip rows.
        // Hidden reviewers (removed by a teacher) never appear in the community.
        $query = LearningResource::query()
            ->where('status', 'approved')
            ->latest()
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('course_code', 'like', $term)
                    ->orWhereHas(
                        'user',
                        fn($user) => $user->where('name', 'like', $term)
                    );
            });
        }

        if (!empty($filters['college'])) {
            $query->where('college', 'like', '%' . $filters['college'] . '%');
        }

        // ?official=1 -> only documents published by faculty (the Official Learning Resources tab).
        if ($request->boolean('official')) {
            $query->whereHas('user', fn($user) => $user->where('role', 'teacher'));
        }

        // The route needs a signed-in user; the token adds bookmark / my-rating info.
        $resources = ResourcePresenter::paginate(
            $query,
            $filters['per_page'] ?? 12,
            $request->user()
        );

        // Standard Laravel pagination JSON: data, current_page, last_page, total, ...
        return response()->json($resources);
    }

    // GET /api/resources/top?sort=rated|reviewed|saved&limit=10
    // Leaderboard of approved reviewers. Hidden reviewers never appear, and a reviewer
    // with nothing to rank on (no ratings / reviews / saves) is left out of that list.
    public function top(Request $request)
    {
        $filters = $request->validate([
            'sort' => ['sometimes', 'in:rated,reviewed,saved,liked'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $sort = $filters['sort'] ?? 'rated';

        $ratings = '(select count(*) from resource_ratings where resource_ratings.learning_resource_id = learning_resources.id)';
        $comments = '(select count(*) from resource_comments where resource_comments.learning_resource_id = learning_resources.id)';
        $saves = '(select count(*) from resource_bookmarks where resource_bookmarks.learning_resource_id = learning_resources.id)';
        $likes = '(select count(*) from resource_likes where resource_likes.learning_resource_id = learning_resources.id)';

        $query = LearningResource::query()->where('status', 'approved');

        if ($sort === 'rated') {
            // Highest average first; more ratings wins a tie, then the newest reviewer.
            $query->whereRaw("$ratings > 0")
                ->orderByRaw('(select avg(rating) from resource_ratings where resource_ratings.learning_resource_id = learning_resources.id) desc')
                ->orderByRaw("$ratings desc");
        } elseif ($sort === 'reviewed') {
            $query->whereRaw("($ratings + $comments) > 0")
                ->orderByRaw("($ratings + $comments) desc");
        } elseif ($sort === 'liked') {
            $query->whereRaw("$likes > 0")
                ->orderByRaw("$likes desc");
        } else {
            $query->whereRaw("$saves > 0")
                ->orderByRaw("$saves desc");
        }

        $query->orderByDesc('learning_resources.id');

        return response()->json([
            'data' => ResourcePresenter::top($query, $filters['limit'] ?? 10, $request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'course_code' => ['required', 'string', 'max:50'],
            'college' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp',
            ],
        ]);

        $file = $request->file('file');
        $path = $file->store('reviewers', 'local');

        $resource = LearningResource::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'course_code' => strtoupper($validated['course_code']),
            'college' => $validated['college'] ?? null,
            'description' => $validated['description'] ?? null,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType()
                ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
        ]);

        return response()->json([
            'message' => 'Reviewer uploaded successfully.',
            'resource' => $resource,
        ], 201);
    }

    public function preview(Request $request, LearningResource $resource)
    {
        $this->abortUnlessViewable($request, $resource);

        abort_unless(
            in_array($resource->mime_type, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ], true),
            415,
            'Preview is not available for this file type. Download it instead.'
        );

        return $this->serveFile($resource, true);
    }

    public function download(Request $request, LearningResource $resource)
    {
        $this->abortUnlessViewable($request, $resource);

        return $this->serveFile($resource, false);
    }

    public function destroy(Request $request, LearningResource $resource)
    {
        // Owners can delete their own uploads; teachers can delete any upload.
        abort_unless(
            $request->user()->id === $resource->user_id
                || $request->user()->role === 'teacher',
            403,
            'You can only delete your own uploads.'
        );

        // Reviewer files live on the private "local" disk only.
        Storage::disk('local')->delete($resource->file_path);

        $resource->delete();

        return response()->json([
            'message' => 'Reviewer deleted successfully.',
        ]);
    }

    // Hidden reviewers can only be opened by their owner or a teacher.
    private function abortUnlessViewable(
        Request $request,
        LearningResource $resource
    ): void {
        if ($resource->status === 'approved') {
            return;
        }

        $user = $request->user();

        abort_unless(
            $user && ($user->role === 'teacher' || $user->id === $resource->user_id),
            404,
            'The reviewer file could not be found.'
        );
    }

    private function serveFile(
        LearningResource $resource,
        bool $inline
    ) {
        // Private disk (storage/app/private): not reachable through /storage,
        // so every download goes through this authenticated route.
        $disk = Storage::disk('local');

        abort_unless(
            $disk->exists($resource->file_path),
            404,
            'The reviewer file could not be found.'
        );

        $disposition = $inline ? 'inline' : 'attachment';

        return response()->file(
            $disk->path($resource->file_path),
            [
                'Content-Type' => $resource->mime_type,
                'Content-Disposition' =>
                $disposition . '; filename="' .
                    addcslashes(
                        basename($resource->original_filename),
                        '"\\'
                    ) . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }
}
