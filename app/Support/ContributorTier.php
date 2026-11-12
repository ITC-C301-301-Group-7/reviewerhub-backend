<?php

namespace App\Support;

use App\Models\LearningResource;
use App\Models\ResourceDownload;
use App\Models\ResourceRating;
use App\Models\User;

/**
 * Tier badges. A student's all-time contributor points decide their tier, and the tier decides
 * how many PDF pages they can preview and how many reviewers they can download per month.
 *
 * Points use the same rules as the leaderboard (see LeaderboardController):
 *   +points_per_upload     per approved reviewer uploaded
 *   +points_per_five_star  per 5-star rating received from someone else
 */
class ContributorTier
{
    /** Tiers from lowest to highest, read from config/reviewerhub.php. */
    public static function all(): array
    {
        return collect(config('reviewerhub.tiers', []))
            ->sortBy('min_points')
            ->values()
            ->all();
    }

    /** All-time points of one user, worked out from real activity. */
    public static function pointsFor(User $user): int
    {
        $uploads = LearningResource::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->count();

        $fiveStars = ResourceRating::query()
            ->join('learning_resources', 'learning_resources.id', '=', 'resource_ratings.learning_resource_id')
            ->where('learning_resources.user_id', $user->id)
            ->where('learning_resources.status', 'approved')
            ->where('resource_ratings.rating', 5)
            ->whereColumn('resource_ratings.user_id', '!=', 'learning_resources.user_id')
            ->count();

        return $uploads * (int) config('reviewerhub.leaderboard.points_per_upload', 50)
            + $fiveStars * (int) config('reviewerhub.leaderboard.points_per_five_star', 10);
    }

    /** The highest tier whose min_points the given points reach. */
    public static function tierForPoints(int $points): array
    {
        $tiers = self::all();
        $current = $tiers[0] ?? [
            'key' => 'tier1',
            'name' => 'Tier 1',
            'min_points' => 0,
            'preview_pages' => 3,
            'downloads_per_month' => 0,
        ];

        foreach ($tiers as $tier) {
            if ($points >= (int) $tier['min_points']) {
                $current = $tier;
            }
        }

        return $current;
    }

    /** Tier key only (for the leaderboard rows). */
    public static function keyForPoints(int $points): string
    {
        return self::tierForPoints($points)['key'];
    }

    /** Different reviewers this user downloaded since the start of this month. */
    public static function downloadedThisMonth(User $user): array
    {
        $since = LeaderboardPeriod::window('month')['since'];

        return ResourceDownload::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->pluck('learning_resource_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Everything the app needs to show the badge and the progress to the next tier.
     * Pass $points when the caller already has them, to skip the extra queries.
     */
    public static function summary(User $user, ?int $points = null): array
    {
        $tiers = self::all();

        // Faculty are not ranked: they always have full access.
        if ($user->role === 'teacher') {
            return [
                'key' => 'faculty',
                'name' => 'Faculty',
                'points' => 0,
                'previewPages' => null,
                'downloadsPerMonth' => null,
                'downloadsUsed' => 0,
                'downloadsLeft' => null,
                'next' => null,
                'pointsToNext' => 0,
                'progress' => 100,
                'tiers' => self::publicList($tiers),
            ];
        }

        $points ??= self::pointsFor($user);
        $current = self::tierForPoints($points);
        $next = collect($tiers)->first(fn($t) => (int) $t['min_points'] > $points);

        $limit = $current['downloads_per_month'];
        $used = count(self::downloadedThisMonth($user));

        // Progress between this tier's start and the next tier's start.
        $progress = 100;
        if ($next) {
            $span = max(1, (int) $next['min_points'] - (int) $current['min_points']);
            $progress = (int) floor((($points - (int) $current['min_points']) / $span) * 100);
        }

        return [
            'key' => $current['key'],
            'name' => $current['name'],
            'points' => $points,
            'previewPages' => $current['preview_pages'],
            'downloadsPerMonth' => $limit,
            'downloadsUsed' => $used,
            'downloadsLeft' => $limit === null ? null : max(0, (int) $limit - $used),
            'next' => $next ? ['key' => $next['key'], 'name' => $next['name'], 'minPoints' => (int) $next['min_points']] : null,
            'pointsToNext' => $next ? (int) $next['min_points'] - $points : 0,
            'progress' => max(0, min(100, $progress)),
            'tiers' => self::publicList($tiers),
        ];
    }

    /**
     * What this user may do with this one reviewer.
     * Owners and faculty see and download everything; everyone else follows their tier.
     */
    public static function accessFor(User $user, LearningResource $resource): array
    {
        $tier = self::summary($user);
        $unlimited = $user->role === 'teacher' || $user->id === $resource->user_id;

        if ($unlimited) {
            return [
                'previewPages' => null,
                'canDownload' => true,
                'downloadsLeft' => null,
                'reason' => null,
                'tier' => $tier,
            ];
        }

        $canDownload = true;
        $reason = null;
        $limit = $tier['downloadsPerMonth'];

        if ($limit !== null) {
            $alreadyHas = in_array($resource->id, self::downloadedThisMonth($user), true);

            if ((int) $limit === 0) {
                $canDownload = false;
                $reason = $tier['next']
                    ? "Downloads unlock at {$tier['next']['name']}. Earn {$tier['pointsToNext']} more points (upload a reviewer or get 5-star ratings) to unlock them."
                    : 'Downloads are not available for your tier.';
            } elseif (!$alreadyHas && $tier['downloadsLeft'] <= 0) {
                $canDownload = false;
                $reason = "You have used all {$limit} downloads of {$tier['name']} this month."
                    . ($tier['next'] ? " Earn {$tier['pointsToNext']} more points to reach {$tier['next']['name']} for more." : '');
            }
        }

        return [
            'previewPages' => $tier['previewPages'],
            'canDownload' => $canDownload,
            'downloadsLeft' => $tier['downloadsLeft'],
            'reason' => $reason,
            'tier' => $tier,
        ];
    }

    /** Remember a download so it counts against the monthly allowance. */
    public static function recordDownload(User $user, LearningResource $resource): void
    {
        // Owners and faculty are not limited, so their downloads are not counted.
        if ($user->role === 'teacher' || $user->id === $resource->user_id) {
            return;
        }

        ResourceDownload::create([
            'user_id' => $user->id,
            'learning_resource_id' => $resource->id,
        ]);
    }

    private static function publicList(array $tiers): array
    {
        return array_map(fn($t) => [
            'key' => $t['key'],
            'name' => $t['name'],
            'minPoints' => (int) $t['min_points'],
            'previewPages' => $t['preview_pages'],
            'downloadsPerMonth' => $t['downloads_per_month'],
        ], $tiers);
    }
}
