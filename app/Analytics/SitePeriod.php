<?php

namespace App\Analytics;

use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\SiteDayCount;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * A run of a site's days read back from its history, and how complete it is.
 *
 * Only verified days are summed. A withheld day and a missing day are named,
 * never drawn as zero.
 */
final class SitePeriod
{
    /**
     * The periods a reader can choose: a number of whole days ending yesterday, or every day.
     */
    public const array CHOICES = ['1', '7', '30', '90', 'all'];

    /**
     * Every day of the period, oldest first, with what history holds for it.
     *
     * @var array<string, ?SiteDay>
     */
    public readonly array $days;

    /**
     * @var array<string, GroupFigures>
     */
    private array $groups = [];

    /**
     * @var array<string, array<string, int>>
     */
    private array $counts = [];

    public function __construct(public readonly Site $site, public readonly CarbonImmutable $first, public readonly CarbonImmutable $last)
    {
        $stored = $site->days()
            ->whereBetween('day', [$first->toDateString(), $last->toDateString()])
            ->with('groups')
            ->get()
            ->keyBy(fn (SiteDay $day) => $day->day->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($first, $last) as $date) {
            $days[$date->toDateString()] = $stored[$date->toDateString()] ?? null;
        }

        $this->days = $days;
        $this->sum();
    }

    /**
     * The last `$length` whole days of the site, ending yesterday where the site is.
     */
    public static function lastDays(Site $site, int $length): self
    {
        $last = now($site->timezone)->subDay()->startOfDay()->toImmutable();

        return new self($site, $last->subDays($length - 1), $last);
    }

    /**
     * Every day since the site's first collected one, ending yesterday.
     */
    public static function allTime(Site $site): self
    {
        $last = now($site->timezone)->subDay()->startOfDay()->toImmutable();
        $first = $site->days()->min('day');

        return new self($site, $first === null ? $last : CarbonImmutable::parse($first)->min($last), $last);
    }

    /**
     * The period a reader chose ('1', '7', '30', '90' or 'all').
     */
    public static function choose(Site $site, string $choice): self
    {
        return $choice === 'all' ? self::allTime($site) : self::lastDays($site, (int) $choice);
    }

    /**
     * The same number of days, just before this period: what its changes are against.
     */
    public function previous(): self
    {
        return new self($this->site, $this->first->subDays($this->length()), $this->first->subDay());
    }

    /**
     * Determine if this period can be compared with another: both have every
     * one of their days verified, so neither sum leaves days out.
     */
    public function comparableWith(self $other): bool
    {
        return $this->verified()->count() === $this->length() && $other->verified()->count() === $other->length();
    }

    /**
     * @return Collection<string, SiteDay>
     */
    public function verified(): Collection
    {
        return collect($this->days)->filter(fn (?SiteDay $day) => $day?->status === DayStatus::Verified);
    }

    /**
     * @return list<string>
     */
    public function withheld(): array
    {
        return array_keys(array_filter($this->days, fn (?SiteDay $day) => $day?->status === DayStatus::Withheld));
    }

    /**
     * Days collected and waiting for their recount.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        return array_keys(array_filter($this->days, fn (?SiteDay $day) => $day?->status === DayStatus::Collected));
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return array_keys(array_filter($this->days, fn (?SiteDay $day) => $day === null));
    }

    public function length(): int
    {
        return count($this->days);
    }

    public function hasFigures(): bool
    {
        return $this->verified()->isNotEmpty();
    }

    /**
     * A group's figures over the verified days.
     */
    public function group(TrafficGroup $group): GroupFigures
    {
        return $this->groups[$group->value];
    }

    /**
     * Every request of the verified days.
     */
    public function requests(): int
    {
        return (int) $this->verified()->sum('hits');
    }

    public function citedVisits(): int
    {
        return (int) $this->verified()->sum('cited_visits');
    }

    /**
     * A breakdown over the verified days: its top rows, the rest folded into one.
     *
     * @return array<string, int>
     */
    public function top(CountKind $kind, int $rows = 10): array
    {
        $counts = $this->counts[$kind->value] ?? [];
        $others = $counts[CountKind::OTHERS] ?? 0;
        unset($counts[CountKind::OTHERS]);
        arsort($counts);

        $kept = array_slice($counts, 0, $rows, true);
        $others += array_sum($counts) - array_sum($kept);

        if ($others > 0) {
            $kept[CountKind::OTHERS] = $others;
        }

        return $kept;
    }

    /**
     * One figure of each day, oldest first, for a sparkline: null for a day without figures.
     *
     * @param  callable(SiteDay): int  $figure
     * @return list<?int>
     */
    public function daily(callable $figure): array
    {
        return array_values(array_map(
            fn (?SiteDay $day) => $day?->status === DayStatus::Verified ? $figure($day) : null,
            $this->days,
        ));
    }

    /**
     * A group's figure of a stored day.
     */
    public static function figureOf(SiteDay $day, TrafficGroup $group, string $figure): int
    {
        return (int) ($day->groups->firstWhere('group', $group)?->{$figure} ?? 0);
    }

    /**
     * The people's figures of each day, for the chart: null for a day with
     * no figures to show (withheld, being checked, or missing).
     *
     * @return list<array{day: string, status: ?string, visitors: ?int, pages: ?int, partial: bool}>
     */
    public function series(): array
    {
        $series = [];

        foreach ($this->days as $date => $day) {
            $people = $day?->status === DayStatus::Verified ? $day->groups->firstWhere('group', TrafficGroup::People) : null;

            $series[] = [
                'day' => $date,
                'status' => $day?->status->value,
                'visitors' => $people === null ? null : (int) $people->readers,
                'pages' => $people === null ? null : (int) $people->views,
                'partial' => (bool) $day?->isPartial(),
            ];
        }

        return $series;
    }

    private function sum(): void
    {
        foreach (TrafficGroup::cases() as $group) {
            $this->groups[$group->value] = new GroupFigures;
        }

        $verified = $this->verified();

        foreach ($verified as $day) {
            foreach ($day->groups as $row) {
                $figures = $this->groups[$row->group->value];
                $figures->requests += $row->requests;
                $figures->views += $row->views;
                $figures->visits += $row->visits;
                $figures->readers += $row->readers;
                $figures->probes += $row->probes;
            }
        }

        SiteDayCount::query()
            ->whereIn('site_day_id', $verified->pluck('id'))
            ->selectRaw('kind, name, SUM(count) as total')
            ->groupBy('kind', 'name')
            ->toBase()
            ->get()
            ->each(function (object $row) {
                $this->counts[$row->kind][$row->name] = (int) $row->total;
            });
    }
}
