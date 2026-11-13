<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyCheckin;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Daily check-in. The app calls this when a student opens the dashboard; the first call of the
 * day earns points_per_checkin, later calls that day change nothing.
 */
class CheckinController extends Controller
{
    // POST /api/checkin
    public function store(Request $request)
    {
        $user = $request->user();
        $points = (int) config('reviewerhub.leaderboard.points_per_checkin', 2);

        // Only students earn points.
        if ($user->role !== 'student') {
            return response()->json(['awarded' => false, 'points' => 0, 'streak' => 0]);
        }

        $timezone = (string) config('reviewerhub.leaderboard.timezone', 'Asia/Manila');
        $today = Carbon::now($timezone)->toDateString();

        $awarded = false;

        try {
            $awarded = DailyCheckin::firstOrCreate([
                'user_id' => $user->id,
                'checkin_date' => $today,
            ])->wasRecentlyCreated;
        } catch (QueryException) {
            // Two tabs checked in at the same moment: today is already counted.
        }

        return response()->json([
            'awarded' => $awarded,
            'points' => $awarded ? $points : 0,
            'date' => $today,
            'streak' => $this->streak($user->id, $today),
        ]);
    }

    /** Days in a row, counting back from today. */
    private function streak(int $userId, string $today): int
    {
        $dates = DailyCheckin::query()
            ->where('user_id', $userId)
            ->orderByDesc('checkin_date')
            ->limit(400)
            ->pluck('checkin_date')
            ->map(fn($date) => Carbon::parse($date)->toDateString());

        $streak = 0;
        $expected = Carbon::parse($today);

        foreach ($dates as $date) {
            if ($date !== $expected->toDateString()) {
                break;
            }

            $streak++;
            $expected->subDay();
        }

        return $streak;
    }
}
