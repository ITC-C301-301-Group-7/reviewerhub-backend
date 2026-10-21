<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;

/**
 * The signed-in user's own class schedule: list, add, edit, delete.
 * Every query is scoped to the current user, so nobody can touch another user's classes.
 */
class ScheduleController extends Controller
{
    private const MAX_CLASSES = 30;

    // GET /api/schedule
    public function index(Request $request)
    {
        return response()->json([
            'entries' => $request->user()
                ->classSchedules()
                ->orderBy('id')
                ->get()
                ->map(fn($entry) => $this->present($entry))
                ->values(),
        ]);
    }

    // POST /api/schedule
    public function store(Request $request)
    {
        $data = $this->validated($request);

        abort_if(
            $request->user()->classSchedules()->count() >= self::MAX_CLASSES,
            422,
            'You can keep up to ' . self::MAX_CLASSES . ' classes on your schedule.'
        );

        $entry = $request->user()->classSchedules()->create($data);

        return response()->json([
            'message' => 'Class added.',
            'entry' => $this->present($entry),
        ], 201);
    }

    // PUT /api/schedule/{entry}
    public function update(Request $request, ClassSchedule $entry)
    {
        $this->authorizeOwner($request, $entry);

        $entry->update($this->validated($request));

        return response()->json([
            'message' => 'Class updated.',
            'entry' => $this->present($entry),
        ]);
    }

    // DELETE /api/schedule/{entry}
    public function destroy(Request $request, ClassSchedule $entry)
    {
        $this->authorizeOwner($request, $entry);

        $entry->delete();

        return response()->json(['message' => 'Class removed.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'instructor' => ['nullable', 'string', 'max:255'],
            'schedule' => ['required', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:10'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }

    // Someone else's class looks like it does not exist.
    private function authorizeOwner(Request $request, ClassSchedule $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404, 'Class not found.');
    }

    private function present(ClassSchedule $e): array
    {
        return [
            'id' => $e->id,
            'code' => $e->code,
            'name' => $e->name,
            'instructor' => $e->instructor ?? '',
            'schedule' => $e->schedule,
            'room' => $e->room ?? '',
            'grade' => $e->grade ?? '',
        ];
    }
}
