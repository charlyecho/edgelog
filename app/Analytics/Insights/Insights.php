<?php

namespace App\Analytics\Insights;

use App\Analytics\Sources;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\SiteDayCount;
use Carbon\CarbonImmutable;

/**
 * Findings worked out from history: what is working, what dropped, what is
 * new or gone, what looks unusual and what is worth fixing (Skald's
 * insights.py). Read from the stored days on each view: every number in a
 * finding is a sum of stored figures.
 *
 *  working, dropped  pages, sources, countries, referring sites, assistants
 *                    that sent visitors, campaigns and AI crawlers: the last
 *                    7 (or 30) whole days against the 7 (or 30) before
 *  gone              a source, referring site, assistant or campaign that
 *                    sent visitors before and none now
 *  new               yesterday, first seen in all the history kept: a page
 *                    read, a source, a country, a crawler, a 404 path
 *  anomaly           yesterday against the 14 days before (median and MAD,
 *                    so one odd day does not move the baseline)
 *  opportunity       over 30 days: pages assistants fetch often that few
 *                    people read; 404s people reach from a link elsewhere
 *
 * Only whole, verified days are compared: a period with a withheld, missing
 * or partial day is not compared at all. MIN_AFTER and MIN_CHANGE keep small
 * numbers out; "new" needs MIN_HISTORY_DAYS stored days before it.
 */
final class Insights
{
    public const int MIN_AFTER = 5;

    public const float MIN_CHANGE = 20.0;

    public const int MIN_NEW = 3;

    public const int MIN_HISTORY_DAYS = 7;

    /**
     * Days of history read: the longest comparison, 30 days on 30, and some.
     */
    private const int HISTORY_DAYS = 90;

    /**
     * The windows a finding is listed under: yesterday, 7 days, 30 days.
     */
    public const array WINDOWS = ['1', '7', '30'];

    /**
     * Each subject's kind: [breakdown, metric].
     *
     * @var array<string, array{0: CountKind, 1: string}>
     */
    private const array SUBJECTS = [
        'page' => [CountKind::PeoplePageVisitors, 'visitors'],
        'source' => [CountKind::PeopleSource, 'visitors'],
        'country' => [CountKind::PeopleCountry, 'visitors'],
        'referrer' => [CountKind::PeopleReferrer, 'visitors'],
        'assistant' => [CountKind::CitedBy, 'visitors'],
        'campaign' => [CountKind::PeopleCampaign, 'visitors'],
        'crawler' => [CountKind::EngineName, 'ai_fetches'],
    ];

    /**
     * Names that are no subject of their own.
     */
    private const array NOT_A_SUBJECT = [Sources::NO_REFERRER, Sources::THIS_SITE, Sources::NO_CAMPAIGN, CountKind::OTHERS, 'Unknown'];

    /**
     * What a moved unit is worth, to order the findings (never shown).
     */
    private const array WORTH = ['visitors' => 1, 'not_found' => 1, 'ai_fetches' => 0.25, 'bots' => 0.1, 'search' => 0.1, 'server_errors' => 2];

    private const array SURE = ['high' => 1, 'medium' => 0.6, 'low' => 0.3];

    /**
     * Each stored day: its status, whether it is whole, its groups and its breakdowns.
     *
     * @var array<string, array{verified: bool, whole: bool, groups: array<string, array<string, int>>, counts: array<string, array<string, int>>}>
     */
    private array $days = [];

    private readonly CarbonImmutable $yesterday;

    public function __construct(public readonly Site $site)
    {
        $this->yesterday = now($site->timezone)->subDay()->startOfDay()->toImmutable();
        $this->load();
    }

    /**
     * Every finding, each with the window it is listed under, most impact first.
     *
     * @return list<array{type: string, window: string, metric: string, kind: string, name: string, before: ?int, after: int, change: ?float, confidence: string, from: string, to: string}>
     */
    public function all(): array
    {
        $found = [
            ...$this->compare(7),
            ...$this->compare(30),
            ...$this->newThings(),
            ...$this->anomalies(),
            ...$this->opportunities(),
        ];

        usort($found, fn (array $a, array $b) => [self::impact($b), $a['kind'].$a['name']] <=> [self::impact($a), $b['kind'].$b['name']]);

        return $found;
    }

    /**
     * The findings of a window, one card per subject: the one with the most
     * impact leads it, the others ride along under it.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<array{lead: array<string, mixed>, more: list<array<string, mixed>>}>
     */
    public static function fold(array $findings): array
    {
        $cards = [];

        foreach ($findings as $finding) {
            $key = strtolower(preg_replace('~^(?:https?://)?(?:www\.)?~i', '', $finding['name']));

            if (! isset($cards[$key])) {
                $cards[$key] = ['lead' => $finding, 'more' => []];
            } else {
                $cards[$key]['more'][] = $finding;
            }
        }

        return array_values($cards);
    }

    /**
     * How much a finding moved, in what it is worth: an order only.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function impact(array $finding): float
    {
        return abs($finding['after'] - ($finding['before'] ?? 0)) * (self::WORTH[$finding['metric']] ?? 1) * (self::SURE[$finding['confidence']] ?? 0.3);
    }

    /**
     * Working, dropped and gone over `span` days against the span before.
     *
     * @return list<array<string, mixed>>
     */
    private function compare(int $span): array
    {
        $after = [$this->yesterday->subDays($span - 1), $this->yesterday];
        $before = [$after[0]->subDays($span), $after[0]->subDay()];

        if (! $this->whole(...$after) || ! $this->whole(...$before)) {
            return [];
        }

        $found = [];

        foreach (self::SUBJECTS as $kind => [$breakdown, $metric]) {
            $now = $this->total($breakdown, ...$after);
            $then = $this->total($breakdown, ...$before);
            $names = array_keys($now + $then);
            sort($names);

            foreach ($names as $name) {
                if (in_array((string) $name, self::NOT_A_SUBJECT, true)) {
                    continue;
                }

                $a = $now[$name] ?? 0;
                $b = $then[$name] ?? 0;
                $change = self::change($b, $a);
                $type = match (true) {
                    $b >= self::MIN_AFTER && $a === 0 && in_array($kind, ['source', 'referrer', 'assistant', 'campaign'], true) => 'gone',
                    $change !== null && $a >= self::MIN_AFTER && $change >= self::MIN_CHANGE => 'working',
                    $change !== null && $b >= self::MIN_AFTER && $change <= -self::MIN_CHANGE => 'dropped',
                    default => null,
                };

                if ($type !== null) {
                    $found[] = $this->finding($type, (string) $span, $metric, $kind, (string) $name, $b, $a, $after);
                }
            }
        }

        return $found;
    }

    /**
     * What yesterday brought that no stored day before it had.
     *
     * @return list<array<string, mixed>>
     */
    private function newThings(): array
    {
        $day = $this->yesterday->toDateString();

        if (! ($this->days[$day]['verified'] ?? false)) {
            return [];
        }

        $before = array_filter($this->days, fn (array $stored, string $date) => $date < $day && $stored['verified'], ARRAY_FILTER_USE_BOTH);

        if (count($before) < self::MIN_HISTORY_DAYS) {
            return [];
        }

        $subjects = [...self::SUBJECTS, 'path' => [CountKind::HealthNotFound, 'not_found']];
        $found = [];

        foreach ($subjects as $kind => [$breakdown, $metric]) {
            $seen = [];

            foreach ($before as $stored) {
                $seen += $stored['counts'][$breakdown->value] ?? [];
            }

            foreach ($this->days[$day]['counts'][$breakdown->value] ?? [] as $name => $count) {
                if (! isset($seen[$name]) && $count >= self::MIN_NEW && ! in_array((string) $name, self::NOT_A_SUBJECT, true)) {
                    $found[] = $this->finding('new', '1', $metric, $kind, (string) $name, null, $count, [$this->yesterday, $this->yesterday], self::MIN_NEW);
                }
            }
        }

        return $found;
    }

    /**
     * Yesterday against the 14 days before it, median and MAD.
     *
     * @return list<array<string, mixed>>
     */
    private function anomalies(): array
    {
        $found = [];

        foreach (['visitors', 'ai_fetches', 'bots', 'search', 'server_errors'] as $metric) {
            $value = $this->metric($metric, $this->yesterday);
            $base = [];

            foreach (range(1, 14) as $back) {
                $earlier = $this->metric($metric, $this->yesterday->subDays($back));

                if ($earlier !== null) {
                    $base[] = $earlier;
                }
            }

            if ($value === null || count($base) < 7) {
                continue;
            }

            $median = self::median($base);
            $spread = (self::median(array_map(fn (int $earlier) => abs($earlier - $median), $base)) * 1.4826) ?: 1.0;

            if (abs($value - $median) >= max(self::MIN_AFTER, 3 * $spread)) {
                $found[] = $this->finding('anomaly', '1', $metric, 'site', $metric, (int) round($median), $value, [$this->yesterday, $this->yesterday]);
            }
        }

        return $found;
    }

    /**
     * Over the last 30 days: pages assistants fetch that few people read,
     * and 404s people reach from a link elsewhere.
     *
     * @return list<array<string, mixed>>
     */
    private function opportunities(): array
    {
        $first = $this->yesterday->subDays(29);
        $fetched = $this->total(CountKind::EnginePage, $first, $this->yesterday, wholeOnly: false);
        $read = $this->total(CountKind::PeoplePageVisitors, $first, $this->yesterday, wholeOnly: false);
        $found = [];

        foreach ($fetched as $path => $fetches) {
            if ($path !== CountKind::OTHERS && $fetches >= 4 * self::MIN_AFTER && ($read[$path] ?? 0) <= max(1, intdiv($fetches, 50))) {
                $found[] = $this->finding('opportunity', '30', 'ai_fetches', 'page', (string) $path, $fetches, $read[$path] ?? 0, [$first, $this->yesterday]);
            }
        }

        $linked = [];

        foreach ($this->total(CountKind::HealthNotFoundFrom, $first, $this->yesterday, wholeOnly: false) as $name => $count) {
            [$path, $from] = str_contains($name, "\t") ? explode("\t", $name, 2) : [$name, Sources::NO_REFERRER];

            if (! in_array($from, [Sources::NO_REFERRER, Sources::THIS_SITE], true)) {
                $linked[$path] = ($linked[$path] ?? 0) + $count;
            }
        }

        foreach ($linked as $path => $people) {
            if ($people >= 2) {
                $found[] = $this->finding('opportunity', '30', 'not_found', 'path', (string) $path, null, $people, [$first, $this->yesterday], 2);
            }
        }

        return $found;
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period  the days the finding is about
     * @return array{type: string, window: string, metric: string, kind: string, name: string, before: ?int, after: int, change: ?float, confidence: string, from: string, to: string}
     */
    private function finding(string $type, string $window, string $metric, string $kind, string $name, ?int $before, int $after, array $period, int $floor = self::MIN_AFTER): array
    {
        $low = $before ? min($before, $after) : $after;

        return [
            'type' => $type,
            'window' => $window,
            'metric' => $metric,
            'kind' => $kind,
            'name' => $name,
            'before' => $before,
            'after' => $after,
            'change' => $before === null ? null : self::change($before, $after),
            'confidence' => $low >= 4 * $floor ? 'high' : ($low >= 2 * $floor ? 'medium' : 'low'),
            'from' => $period[0]->toDateString(),
            'to' => $period[1]->toDateString(),
        ];
    }

    /**
     * Determine if every day from `$first` to `$last` is verified and whole.
     */
    private function whole(CarbonImmutable $first, CarbonImmutable $last): bool
    {
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (! ($this->days[$day->toDateString()]['whole'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A breakdown summed from `$first` to `$last`: over the verified days
     * only, or over the whole days only.
     *
     * @return array<string, int>
     */
    private function total(CountKind $breakdown, CarbonImmutable $first, CarbonImmutable $last, bool $wholeOnly = true): array
    {
        $total = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $stored = $this->days[$day->toDateString()] ?? null;

            if ($stored === null || ! $stored[$wholeOnly ? 'whole' : 'verified']) {
                continue;
            }

            foreach ($stored['counts'][$breakdown->value] ?? [] as $name => $count) {
                $total[$name] = ($total[$name] ?? 0) + $count;
            }
        }

        return $total;
    }

    /**
     * One of the site's daily figures, or null for a day that is not whole.
     */
    private function metric(string $metric, CarbonImmutable $day): ?int
    {
        $stored = $this->days[$day->toDateString()] ?? null;

        if ($stored === null || ! $stored['whole']) {
            return null;
        }

        return match ($metric) {
            'visitors' => $stored['groups'][TrafficGroup::People->value]['readers'] ?? 0,
            'ai_fetches' => $stored['groups'][TrafficGroup::AnswerEngines->value]['requests'] ?? 0,
            'bots' => $stored['groups'][TrafficGroup::Bots->value]['requests'] ?? 0,
            'search' => $stored['groups'][TrafficGroup::SearchEngines->value]['requests'] ?? 0,
            'server_errors' => array_sum(array_filter($stored['counts'][CountKind::HealthStatus->value] ?? [], fn (string $name) => str_ends_with($name, ':5xx'), ARRAY_FILTER_USE_KEY)),
        };
    }

    private static function change(int $before, int $after): ?float
    {
        return $before === 0 ? null : round(($after - $before) / $before * 100, 1);
    }

    /**
     * @param  list<int|float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Read the last HISTORY_DAYS days of the site, and every subject's breakdowns.
     */
    private function load(): void
    {
        $stored = SiteDay::query()
            ->where('site_id', $this->site->id)
            ->where('day', '>=', $this->yesterday->subDays(self::HISTORY_DAYS - 1)->toDateString())
            ->where('day', '<=', $this->yesterday->toDateString())
            ->with('groups')
            ->get();

        $verified = $stored->filter(fn (SiteDay $day) => $day->status === DayStatus::Verified);
        $dates = $verified->mapWithKeys(fn (SiteDay $day) => [$day->id => $day->day->toDateString()]);

        foreach ($stored as $day) {
            $this->days[$day->day->toDateString()] = [
                'verified' => $day->status === DayStatus::Verified,
                'whole' => $day->status === DayStatus::Verified && ! $day->isPartial(),
                'groups' => $day->groups->mapWithKeys(fn ($group) => [$group->group->value => $group->only(['requests', 'readers'])])->all(),
                'counts' => [],
            ];
        }

        $kinds = [...array_column(self::SUBJECTS, 0), CountKind::HealthNotFound, CountKind::HealthNotFoundFrom, CountKind::HealthStatus, CountKind::EnginePage];

        SiteDayCount::query()
            ->whereIn('site_day_id', $dates->keys())
            ->whereIn('kind', $kinds)
            ->toBase()
            ->get(['site_day_id', 'kind', 'name', 'count'])
            ->each(function (object $row) use ($dates) {
                $this->days[$dates[$row->site_day_id]]['counts'][$row->kind][$row->name] = (int) $row->count;
            });
    }
}
