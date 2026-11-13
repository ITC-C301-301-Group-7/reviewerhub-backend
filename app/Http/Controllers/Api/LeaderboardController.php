<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningResource;
use App\Models\ResourceRating;
use App\Models\User;
use App\Support\BonusPoints;
use App\Support\ContributorTier;
use App\Support\LeaderboardPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Contributor leaderboard for the Reviewer Community.
 *
 * Points are worked out from real activity inside the chosen period:
 *   +points_per_upload     for every approved reviewer a student uploaded
 *   +points_per_five_star  for every 5-star rating a student's reviewers received from someone else
 * plus the bonus points in BonusPoints (daily check-ins, reports, 50+ download milestones).
 */
class LeaderboardController extends Controller
{
    // GET /api/leaderboard?period=month|semester|all&limit=20
    public function index(Request $request)
    {
        $filters = $request->validate([
            'period' => ['sometimes', 'in:month,semester,all'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $period = $filters['period'] ?? 'month';
        $limit = $filters['limit'] ?? 20;
        $window = LeaderboardPeriod::window($period);

        $ranked = $this->ranked($window['since']);
        $allTime = $period === 'all' ? $ranked : $this->ranked(null);

        // A student's tier always comes from their ALL-TIME points, even on the monthly board.
        $tiers = $allTime->mapWithKeys(
            fn($row) => [$row['userId'] => ContributorTier::keyForPoints($row['points'])]
        );

        $me = $request->user()->id;
        $mine = $ranked->firstWhere('userId', $me);
        $mineAll = $allTime->firstWhere('userId', $me);

        return response()->json([
            'period' => $period,
            'label' => $window['label'],
            'since' => $window['since']?->toIso8601String(),
            'total' => $ranked->count(),
            'data' => $ranked->take($limit)
                ->map(fn($row) => $row + ['tier' => $tiers[$row['userId']] ?? 'tier0'])
                ->values()
                ->all(),
            // The signed-in student in this period (rank is null until they have points) ...
            'me' => [
                'rank' => $mine['rank'] ?? null,
                'points' => $mine['points'] ?? 0,
                'contributions' => $mine['contributions'] ?? 0,
            ],
            // ... and over all time, for the "My Points" badge.
            'allTime' => [
                'points' => $mineAll['points'] ?? 0,
                'contributions' => $mineAll['contributions'] ?? 0,
                'tier' => ContributorTier::keyForPoints($mineAll['points'] ?? 0),
            ],
            'rules' => [
                'upload' => (int) config('reviewerhub.leaderboard.points_per_upload', 50),
                'fiveStar' => (int) config('reviewerhub.leaderboard.points_per_five_star', 10),
                'milestone' => (int) config('reviewerhub.leaderboard.points_per_milestone', 25),
                'report' => (int) config('reviewerhub.leaderboard.points_per_report', 5),
                'checkin' => (int) config('reviewerhub.leaderboard.points_per_checkin', 2),
            ],
        ]);
    }

    /**
     * Every student with points since $since (null = all time), best first, with their rank.
     */
    private function ranked(?Carbon $since): Collection
    {
        $uploadPoints = (int) config('reviewerhub.leaderboard.points_per_upload', 50);
        $starPoints = (int) config('reviewerhub.leaderboard.points_per_five_star', 10);

        // Approved reviewers uploaded in the period (hidden ones never count).
        $uploads = LearningResource::query()
            ->where('status', 'approved')
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // 5-star ratings given (or last changed) in the period, credited to the reviewer's owner.
        $stars = ResourceRating::query()
            ->join('learning_resources', 'learning_resources.id', '=', 'resource_ratings.learning_resource_id')
            ->where('learning_resources.status', 'approved')
            ->where('resource_ratings.rating', 5)
            ->whereColumn('resource_ratings.user_id', '!=', 'learning_resources.user_id')
            ->when($since, fn($q) => $q->where('resource_ratings.updated_at', '>=', $since))
            ->selectRaw('learning_resources.user_id as owner_id, COUNT(*) as total')
            ->groupBy('learning_resources.user_id')
            ->pluck('total', 'owner_id');

        // Daily check-ins, reports and 50+ download milestones in the same period.
        $bonus = BonusPoints::totals($since);

        $ids = $uploads->keys()->merge($stars->keys())->merge($bonus->keys())->unique()->values();

        $users = User::query()
            ->whereIn('id', $ids)
            ->where('role', 'student')
            ->get()
            ->keyBy('id');

        return $ids
            ->filter(fn($id) => $users->has($id))
            ->map(function ($id) use ($users, $uploads, $stars, $bonus, $uploadPoints, $starPoints) {
                $user = $users[$id];
                $uploaded = (int) ($uploads[$id] ?? 0);
                $fiveStars = (int) ($stars[$id] ?? 0);

                return [
                    'userId' => $user->id,
                    'name' => $user->name,
                    'college' => $user->department,
                    'program' => $user->year_level,
                    'points' => $uploaded * $uploadPoints + $fiveStars * $starPoints + (int) $bonus->get($id, 0),
                    'contributions' => $uploaded,
                    'fiveStarRatings' => $fiveStars,
                ];
            })
            // Most points first; more uploads, then the older account, break ties so ranks are stable.
            ->sort(fn($a, $b) => [-$a['points'], -$a['contributions'], $a['userId']]
                <=> [-$b['points'], -$b['contributions'], $b['userId']])
            ->values()
            ->map(fn($row, $index) => $row + ['rank' => $index + 1]);
    }
}
