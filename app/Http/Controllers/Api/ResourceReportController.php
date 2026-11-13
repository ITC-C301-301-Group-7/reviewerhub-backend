<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Models\ResourceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Students can flag a reviewer that is fake, inaccurate, spam or inappropriate.
 * A report earns points_per_report points, up to max_rewarded_reports_per_day per day.
 */
class ResourceReportController extends Controller
{
    // POST /api/resources/{resource}/report   { reason, details? }
    public function store(Request $request, LearningResource $resource)
    {
        $data = $request->validate([
            'reason' => ['required', 'in:inaccurate,spam,inappropriate,other'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        abort_unless($resource->status === 'approved', 404, 'The reviewer file could not be found.');
        abort_if($resource->user_id === $user->id, 422, 'You cannot report your own upload.');

        $report = ResourceReport::firstOrCreate(
            ['user_id' => $user->id, 'learning_resource_id' => $resource->id],
            ['reason' => $data['reason'], 'details' => $data['details'] ?? null]
        );

        if (! $report->wasRecentlyCreated) {
            return response()->json(['reported' => true, 'alreadyReported' => true, 'pointsAwarded' => 0]);
        }

        $cap = max(1, (int) config('reviewerhub.leaderboard.max_rewarded_reports_per_day', 3));
        $today = Carbon::now((string) config('app.timezone'))->toDateString();
        $reportedToday = ResourceReport::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', $today)
            ->count();

        $rewarded = $user->role === 'student' && $reportedToday <= $cap;

        return response()->json([
            'reported' => true,
            'alreadyReported' => false,
            'pointsAwarded' => $rewarded ? (int) config('reviewerhub.leaderboard.points_per_report', 5) : 0,
        ], 201);
    }
}
