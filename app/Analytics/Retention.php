<?php

namespace App\Analytics;

use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\SiteDayGroup;
use App\Models\SiteVisitorDay;
use Carbon\CarbonImmutable;

/**
 * Retention cohorts: the visitors first seen in each week (or day), and the
 * share of them back in each week (or day) after.
 *
 * The same visitor is the same keyed hash of address and browser
 * (VisitorKey), kept analytics.visitors_kept_days. New means first seen
 * since what is kept begins. A cell is counted only once its week is over
 * and every one of its days kept its visitors: otherwise it is not over
 * yet, or not recorded, never 0. A cohort over before what is kept begins
 * is not shown. One address can be several people (an
 * office), and one person on two devices is two visitors.
 */
final class Retention
{
    public const array GRAINS = ['week' => [12, 8], 'day' => [30, 14]];

    /**
     * Each cohort, newest first: its first day, its new visitors, and each
     * later period's visitors back, null when not over or not recorded.
     *
     * @var list<array{start: CarbonImmutable, end: CarbonImmutable, new: int, back: list<?int>, state: list<string>}>
     */
    public array $cohorts = [];

    /**
     * The first day whose visitors are kept, or null when none are.
     */
    public ?CarbonImmutable $keptFrom = null;

    public function __construct(public readonly Site $site, public readonly string $grain = 'week', public readonly bool $readers = false)
    {
        [$count, $follow] = self::GRAINS[$grain];
        $today = now($site->timezone)->startOfDay()->toImmutable();
        $length = $grain === 'week' ? 7 : 1;
        $lastStart = $grain === 'week' ? $today->startOfWeek()->subWeek() : $today->subDay();
        $firstStart = $lastStart->subDays($length * ($count - 1));

        $days = $site->days()->get(['id', 'day', 'status'])->keyBy('id');
        $verified = $days->filter(fn ($day) => $day->status === DayStatus::Verified);
        $rows = SiteVisitorDay::query()->whereIn('site_day_id', $verified->keys())->toBase()->get(['site_day_id', 'visitor', 'views']);

        // A day kept its visitors when it has their hashes, or had none to keep.
        $withVisitors = $rows->pluck('site_day_id')->unique()->flip();
        $quiet = SiteDayGroup::query()->whereIn('site_day_id', $verified->keys())->where('group', TrafficGroup::People)->where('readers', 0)->pluck('site_day_id')->flip();
        $recorded = $verified->filter(fn ($day, $id) => isset($withVisitors[$id]) || isset($quiet[$id]))->mapWithKeys(fn ($day) => [$day->day->toDateString() => true])->all();

        $first = [];
        $seen = [];
        $dateOf = $days->map(fn ($day) => $day->day->toDateString())->all();

        foreach ($rows as $row) {
            $date = $dateOf[$row->site_day_id];
            $first[$row->visitor] = min($first[$row->visitor] ?? $date, $date);

            if (! $this->readers || $row->views >= 2) {
                $seen[$row->visitor][$date] = true;
            }
        }

        $this->keptFrom = $first === [] ? null : CarbonImmutable::parse(min($first));
        $byStart = [];
        $indexOf = [];
        $firstDate = $firstStart->toDateString();

        foreach ($first as $visitor => $date) {
            $index = $indexOf[$date] ??= intdiv((int) $firstStart->diffInDays(CarbonImmutable::parse($date), false), $length);

            if ($date >= $firstDate && $index < $count) {
                $byStart[$index][] = $visitor;
            }
        }

        for ($index = $count - 1; $index >= 0; $index--) {
            $start = $firstStart->addDays($index * $length);

            if ($this->keptFrom === null || $start->addDays($length - 1)->lessThan($this->keptFrom)) {
                continue;
            }

            $visitors = $byStart[$index] ?? [];
            $back = [];
            $state = [];

            for ($later = 1; $later <= $follow; $later++) {
                $from = $start->addDays($later * $length);
                $to = $from->addDays($length - 1);
                $dates = array_map(fn (int $offset) => $from->addDays($offset)->toDateString(), range(0, $length - 1));

                if ($to->greaterThanOrEqualTo($today)) {
                    $back[] = null;
                    $state[] = 'open';

                    continue;
                }

                if (array_diff($dates, array_keys($recorded)) !== []) {
                    $back[] = null;
                    $state[] = 'missing';

                    continue;
                }

                $wanted = array_flip($dates);
                $back[] = count(array_filter($visitors, fn (string $visitor) => array_intersect_key($seen[$visitor] ?? [], $wanted) !== []));
                $state[] = 'counted';
            }

            $this->cohorts[] = ['start' => $start, 'end' => $start->addDays($length - 1), 'new' => count($visitors), 'back' => $back, 'state' => $state];
        }
    }

    /**
     * The share of new visitors back in the given later period, over every
     * cohort counted there, with its parts.
     *
     * @return array{back: int, of: int}
     */
    public function backIn(int $later): array
    {
        $back = 0;
        $of = 0;

        foreach ($this->cohorts as $cohort) {
            if (($cohort['state'][$later - 1] ?? null) === 'counted') {
                $back += $cohort['back'][$later - 1];
                $of += $cohort['new'];
            }
        }

        return ['back' => $back, 'of' => $of];
    }

    /**
     * The new visitors of every cohort.
     */
    public function newVisitors(): int
    {
        return array_sum(array_column($this->cohorts, 'new'));
    }

    public function follow(): int
    {
        return self::GRAINS[$this->grain][1];
    }
}
