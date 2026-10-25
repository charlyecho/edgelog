<?php

namespace App\Analytics\Health;

use App\Analytics\SitePeriod;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Models\SiteDay;
use Carbon\CarbonImmutable;

/**
 * A period's health, read back from its verified days: the answers by group
 * and status, the answer times of people's page views, and the heartbeat.
 */
final readonly class HealthPeriod
{
    public function __construct(public SitePeriod $period) {}

    /**
     * Determine if every verified day was counted with its health (days
     * counted before it was kept have none).
     */
    public function isWhole(): bool
    {
        return $this->period->hasFigures() && array_sum($this->period->counts(CountKind::HealthStatus)) === $this->period->requests();
    }

    /**
     * Each group's answers by status class, and all of them.
     *
     * @return array<string, array<string, int>>
     */
    public function statuses(): array
    {
        $statuses = $this->period->counts(CountKind::HealthStatus);
        $rows = [];

        foreach (TrafficGroup::cases() as $group) {
            $row = [];

            foreach (HealthCollector::STATUSES as $status) {
                $row[$status] = $statuses["{$group->value}:{$status}"] ?? 0;
            }

            // "404" is an integer key: added to, never spread, so it keeps it.
            $row['all'] = array_sum($row);
            $rows[$group->value] = $row;
        }

        return $rows;
    }

    /**
     * Answers of one status class, every group together.
     */
    public function status(string $status): int
    {
        return array_sum(array_column($this->statuses(), $status));
    }

    /**
     * People's page views by answer time, fastest first.
     *
     * @return array<string, int>
     */
    public function speeds(): array
    {
        $speeds = $this->period->counts(CountKind::HealthSpeed);

        return array_map(fn (string $speed) => $speeds[$speed] ?? 0, array_combine(array_keys(HealthCollector::SPEEDS), array_keys(HealthCollector::SPEEDS)));
    }

    public function notLogged(): int
    {
        return $this->period->counts(CountKind::HealthSpeed)[HealthCollector::NOT_LOGGED] ?? 0;
    }

    /**
     * The answer time the share of people's timed page views falls in: 0.5
     * for the median, 0.95 for the slowest 5%. Only buckets are kept.
     */
    public function speedAt(float $share): ?string
    {
        $speeds = $this->speeds();
        $total = array_sum($speeds);
        $seen = 0;

        if ($total === 0) {
            return null;
        }

        foreach ($speeds as $speed => $views) {
            $seen += $views;

            if ($seen >= $share * $total) {
                return $speed;
            }
        }

        return array_key_last($speeds);
    }

    public function slowViews(): int
    {
        return array_sum(array_intersect_key($this->speeds(), array_flip(HealthCollector::SLOW)));
    }

    /**
     * The heartbeat over the verified days it beat on: slots expected and
     * answered, and every gap, newest first. Null when it never beat.
     *
     * @return ?array{days: int, expected: int, up: int, failed: int, gaps: list<array{from: CarbonImmutable, to: CarbonImmutable, minutes: int}>}
     */
    public function heartbeat(): ?array
    {
        $days = $this->period->verified()->filter(fn (SiteDay $day) => $day->heartbeat !== null);

        if ($days->isEmpty()) {
            return null;
        }

        $gaps = $days->flatMap(fn (SiteDay $day) => array_map(fn (array $gap) => [
            'from' => CarbonImmutable::createFromTimestamp($gap['from']),
            'to' => CarbonImmutable::createFromTimestamp($gap['to']),
            'minutes' => $gap['minutes'],
        ], $day->heartbeat['gaps']))->sortByDesc('from')->values()->all();

        return [
            'days' => $days->count(),
            'expected' => (int) $days->sum(fn (SiteDay $day) => $day->heartbeat['expected']),
            'up' => (int) $days->sum(fn (SiteDay $day) => $day->heartbeat['up']),
            'failed' => (int) $days->sum(fn (SiteDay $day) => $day->heartbeat['failed']),
            'gaps' => $gaps,
        ];
    }

    /**
     * The form posts by group and page, most first: a folded tail has no group.
     *
     * @return list<array{group: ?TrafficGroup, path: string, posts: int}>
     */
    public function posts(): array
    {
        $rows = [];

        foreach ($this->period->counts(CountKind::HealthPost) as $name => $posts) {
            [$group, $path] = str_contains($name, "\t") ? explode("\t", $name, 2) : ['', $name];
            $rows[] = ['group' => TrafficGroup::tryFrom($group), 'path' => $path, 'posts' => $posts];
        }

        usort($rows, fn (array $a, array $b) => $b['posts'] <=> $a['posts']);

        return $rows;
    }

    /**
     * Each path people met a 404 on: how many times, where they came from,
     * the first and last day, and whether it is fixed (people read it as a
     * page on a later day). Most hit first.
     *
     * @return list<array{path: string, people: int, from: array<string, int>, first: string, last: string, fixed: bool}>
     */
    public function brokenPaths(): array
    {
        $paths = [];

        foreach ($this->period->dailyCounts(CountKind::HealthNotFound) as $day => $counts) {
            foreach ($counts as $path => $count) {
                $row = $paths[$path] ?? ['path' => (string) $path, 'people' => 0, 'from' => [], 'first' => $day, 'last' => $day, 'fixed' => false];
                $row['people'] += $count;
                $row['first'] = min($row['first'], $day);
                $row['last'] = max($row['last'], $day);
                $paths[$path] = $row;
            }
        }

        foreach ($this->period->counts(CountKind::HealthNotFoundFrom) as $name => $count) {
            [$path, $from] = str_contains($name, "\t") ? explode("\t", $name, 2) : [$name, ''];

            if (isset($paths[$path])) {
                $paths[$path]['from'][$from] = ($paths[$path]['from'][$from] ?? 0) + $count;
            }
        }

        foreach ($this->period->dailyCounts(CountKind::PeoplePage) as $day => $pages) {
            foreach (array_intersect_key($pages, $paths) as $path => $views) {
                $paths[$path]['fixed'] = $paths[$path]['fixed'] || $day > $paths[$path]['last'];
            }
        }

        unset($paths[CountKind::OTHERS]);
        $paths = array_map(function (array $row) {
            arsort($row['from']);

            return $row;
        }, array_values($paths));
        usort($paths, fn (array $a, array $b) => [$a['fixed'], $b['people']] <=> [$b['fixed'], $a['people']]);

        return $paths;
    }

    /**
     * People's page views by page with their server time: timed views,
     * average and slow views, slowest average first.
     *
     * @return list<array{path: string, views: int, average: int, slow: int}>
     */
    public function pageTimes(): array
    {
        $milliseconds = $this->period->counts(CountKind::HealthPageMs);
        $slow = $this->period->counts(CountKind::HealthSlowPage);
        $rows = [];

        foreach ($this->period->counts(CountKind::HealthPageTimed) as $path => $views) {
            if ($path === CountKind::OTHERS || $views === 0 || ! isset($milliseconds[$path])) {
                continue;
            }

            $rows[] = ['path' => (string) $path, 'views' => $views, 'average' => (int) round($milliseconds[$path] / $views), 'slow' => $slow[$path] ?? 0];
        }

        usort($rows, fn (array $a, array $b) => [$b['average'], $b['views']] <=> [$a['average'], $a['views']]);

        return $rows;
    }

    /**
     * Each day's people's page views by answer time, oldest first; null for
     * a day without figures.
     *
     * @return array<string, ?array<string, int>>
     */
    public function dailySpeeds(): array
    {
        $daily = $this->period->dailyCounts(CountKind::HealthSpeed);
        $days = [];

        foreach ($this->period->days as $date => $day) {
            $days[$date] = $this->period->verified()->has($date)
                ? array_map(fn (string $speed) => $daily[$date][$speed] ?? 0, array_combine(array_keys(HealthCollector::SPEEDS), array_keys(HealthCollector::SPEEDS)))
                : null;
        }

        return $days;
    }
}
