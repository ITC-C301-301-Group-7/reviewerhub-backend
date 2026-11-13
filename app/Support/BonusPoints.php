<?php

namespace App\Support;

use App\Models\DailyCheckin;
use App\Models\ResourceDownload;
use App\Models\ResourceReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Points besides uploads and 5-star ratings (those two are counted in LeaderboardController and
 * ContributorTier). Everything is worked out from real activity, so nothing needs to be "awarded":
 *
 *   points_per_checkin     for every day the student opened the app (daily_checkins)
 *   points_per_report      for every reviewer the student reported, up to
 *                          max_rewarded_reports_per_day per day (resource_reports)
 *   points_per_milestone   for every approved reviewer that reached milestone_downloads different
 *                          downloaders (resource_downloads, the owner's own downloads excluded)
 *
 * Pass $since to count only activity from that moment (the leaderboard periods) and $userId to
 * count one student only.
 */
class BonusPoints
{
    /** @return Collection<int, int>  user id => bonus points */
    public static function totals(?Carbon $since = null, ?int $userId = null): Collection
    {
        $totals = collect();

        $parts = [
            self::checkins($since, $userId)
                ->map(fn($n) => $n * (int) config('reviewerhub.leaderboard.points_per_checkin', 2)),
            self::reports($since, $userId)
                ->map(fn($n) => $n * (int) config('reviewerhub.leaderboard.points_per_report', 5)),
            self::milestones($since, $userId)
                ->map(fn($n) => $n * (int) config('reviewerhub.leaderboard.points_per_milestone', 25)),
        ];

        foreach ($parts as $part) {
            foreach ($part as $id => $points) {
                $totals->put($id, (int) $totals->get($id, 0) + (int) $points);
            }
        }

        return $totals;
    }

    /** Number of check-in days, per user. */
    public static function checkins(?Carbon $since, ?int $userId): Collection
    {
        $timezone = (string) config('reviewerhub.leaderboard.timezone', 'Asia/Manila');

        return DailyCheckin::query()
            ->when($since, fn($q) => $q->where('checkin_date', '>=', $since->copy()->timezone($timezone)->toDateString()))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn($n) => (int) $n);
    }

    /** Number of rewarded reports, per user (a daily cap stops report spam from farming points). */
    public static function reports(?Carbon $since, ?int $userId): Collection
    {
        $cap = max(1, (int) config('reviewerhub.leaderboard.max_rewarded_reports_per_day', 3));

        $days = ResourceReport::query()
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->selectRaw('user_id, DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('user_id', DB::raw('DATE(created_at)'))
            ->get();

        return $days
            ->groupBy('user_id')
            ->map(fn($rows) => (int) $rows->sum(fn($row) => min($cap, (int) $row->total)));
    }

    /** Number of milestone reviewers, per owner. */
    public static function milestones(?Carbon $since, ?int $userId): Collection
    {
        $needed = max(1, (int) config('reviewerhub.leaderboard.milestone_downloads', 50));

        $candidates = ResourceDownload::query()
            ->join('learning_resources', 'learning_resources.id', '=', 'resource_downloads.learning_resource_id')
            ->where('learning_resources.status', 'approved')
            ->whereColumn('resource_downloads.user_id', '!=', 'learning_resources.user_id')
            ->when($userId, fn($q) => $q->where('learning_resources.user_id', $userId))
            ->selectRaw('resource_downloads.learning_resource_id as resource_id, learning_resources.user_id as owner_id')
            ->groupBy('resource_downloads.learning_resource_id', 'learning_resources.user_id')
            ->havingRaw('COUNT(DISTINCT resource_downloads.user_id) >= ?', [$needed])
            ->get();

        $counts = collect();

        foreach ($candidates as $row) {
            // The moment the Nth different student first downloaded it = when the milestone was reached.
            $reachedAt = ResourceDownload::query()
                ->where('learning_resource_id', $row->resource_id)
                ->selectRaw('user_id, MIN(created_at) as first_at')
                ->groupBy('user_id')
                ->orderBy('first_at')
                ->offset($needed - 1)
                ->limit(1)
                ->value('first_at');

            if ($since && $reachedAt && Carbon::parse($reachedAt, (string) config('app.timezone'))->lt($since)) {
                continue;
            }

            $counts->put($row->owner_id, (int) $counts->get($row->owner_id, 0) + 1);
        }

        return $counts;
    }
}
