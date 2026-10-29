<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Turns "month" / "semester" / "all" into the date the leaderboard counts from.
 */
class LeaderboardPeriod
{
    /**
     * @return array{since: ?Carbon, label: string}  since is null for "all" (no lower limit).
     */
    public static function window(string $period): array
    {
        if ($period === 'all') {
            return ['since' => null, 'label' => 'All time'];
        }

        $timezone = (string) config('reviewerhub.leaderboard.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);

        if ($period === 'month') {
            $start = $now->copy()->startOfMonth();
            $label = $now->format('F Y');
        } else {
            $semesters = collect(config('reviewerhub.leaderboard.semesters', []))
                ->sortBy('start_month')
                ->values();

            if ($semesters->isEmpty()) {
                $semesters = collect([['start_month' => 8, 'label' => 'Semester']]);
            }

            // Latest start on or before this month; before the first start, the previous year's last one.
            $current = $semesters->last(fn($s) => (int) $s['start_month'] <= $now->month) ?? $semesters->last();

            $start = $now->copy()->startOfMonth()->setMonth((int) $current['start_month']);
            if ($start->gt($now)) {
                $start->subYear();
            }

            $label = $current['label'] . ' (since ' . $start->format('M j, Y') . ')';
        }

        // created_at is stored in the application's timezone, so compare in that timezone.
        return ['since' => $start->setTimezone((string) config('app.timezone')), 'label' => $label];
    }
}
