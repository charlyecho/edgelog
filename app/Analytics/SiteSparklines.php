<?php

namespace App\Analytics;

use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\SiteDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Each site's visitors over its last days, for the list of sites: one query
 * for every site, each cut in its own time zone. A day without verified
 * figures is null, never zero.
 */
final class SiteSparklines
{
    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, list<?int>> by site id, oldest day first
     */
    public static function visitors(Collection $sites, int $days = 30): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $ranges = $sites->mapWithKeys(function (Site $site) use ($days) {
            $last = now($site->timezone)->subDay()->startOfDay()->toImmutable();

            return [$site->id => [$last->subDays($days - 1), $last]];
        });

        $stored = SiteDay::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->where('status', DayStatus::Verified)
            ->whereBetween('day', [
                $ranges->min(fn (array $range) => $range[0])->toDateString(),
                $ranges->max(fn (array $range) => $range[1])->toDateString(),
            ])
            ->with(['groups' => fn ($query) => $query->where('group', TrafficGroup::People)])
            ->get()
            ->groupBy('site_id');

        return $ranges->map(function (array $range, int $siteId) use ($stored) {
            /** @var array{0: CarbonImmutable, 1: CarbonImmutable} $range */
            $byDay = ($stored[$siteId] ?? collect())->keyBy(fn (SiteDay $day) => $day->day->toDateString());

            return collect(CarbonPeriod::create($range[0], $range[1]))
                ->map(fn ($date) => isset($byDay[$date->toDateString()])
                    ? (int) ($byDay[$date->toDateString()]->groups->first()?->readers ?? 0)
                    : null)
                ->values()
                ->all();
        })->all();
    }
}
