<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
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
        ]);

        // Newest first; id breaks ties so pages never repeat or skip rows.
        $query = LearningResource::with('user:id,name')
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

        $resources = $query
            ->paginate($filters['per_page'] ?? 12)
            ->through(function ($resource) {
                return [
                    'id' => $resource->id,
                    'title' => $resource->title,
                    'courseCode' => $resource->course_code,
                    'college' => $resource->college,
                    'description' => $resource->description,
                    'author' => $resource->user?->name ?? 'ReviewerHub User',
                    'userId' => $resource->user_id,
                    'createdAt' => $resource->created_at?->toIso8601String(),
                    'filename' => $resource->original_filename,
                    'mimeType' => $resource->mime_type,
                    'fileSize' => $resource->file_size,
                    'previewUrl' => url(
                        "/api/resources/{$resource->id}/preview"
                    ),
                    'downloadUrl' => url(
                        "/api/resources/{$resource->id}/download"
                    ),
                ];
            });

        // Standard Laravel pagination JSON: data, current_page, last_page, total, ...
        return response()->json($resources);
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

    public function preview(LearningResource $resource)
    {
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

    public function download(LearningResource $resource)
    {
        return $this->serveFile($resource, false);
    }

    public function destroy(Request $request, LearningResource $resource)
    {
        abort_unless(
            $request->user()->id === $resource->user_id,
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
