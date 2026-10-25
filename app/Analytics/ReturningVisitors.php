<?php

namespace App\Analytics;

use App\Enums\CountKind;
use App\Models\Site;
use App\Models\SiteVisitorDay;
use Carbon\CarbonImmutable;

/**
 * Tells each visit of a day new or returning: returning when its visitor's
 * keyed hash was seen on one of the analytics.returning_days before.
 * Counted per visit, so two visits on a visitor's first day are both new.
 */
final class ReturningVisitors
{
    public static function mark(Site $site, string $day, DayFigures $figures): void
    {
        $date = CarbonImmutable::parse($day);
        $days = (int) config('analytics.returning_days');

        $seen = SiteVisitorDay::query()
            ->whereHas('day', fn ($query) => $query->where('site_id', $site->id)
                ->whereBetween('day', [$date->subDays($days - 1)->toDateString(), $date->subDay()->toDateString()]))
            ->whereIn('visitor', array_keys($figures->visitors))
            ->pluck('visitor')
            ->flip();

        foreach ($figures->journeys as &$journey) {
            $journey['returning'] = isset($seen[$journey['visitor']]);
            $figures->count(CountKind::VisitorReturning, $journey['returning'] ? 'returning' : 'new');

            if ($journey['returning']) {
                $figures->count(CountKind::ReturningSource, $journey['source']);
            }
        }
    }
}
