<?php

namespace App\Analytics\Crawlers;

use App\Analytics\SitePeriod;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Models\SiteDayCrawler;
use Carbon\CarbonImmutable;

/**
 * A period's crawlers, assistants and bots, read back from its verified days.
 */
final class CrawlerPeriod
{
    public function __construct(public readonly SitePeriod $period) {}

    /**
     * Each name's figures over the period, busiest first; one group only when given.
     *
     * @param  ?callable(string): bool  $keep  which names to keep
     * @return list<array{group: TrafficGroup, name: string, requests: int, bytes: int, mean_ms: ?int, p90_ms: ?int, errors: int, paths: int, last_seen_at: CarbonImmutable}>
     */
    public function costs(?TrafficGroup $group = null, ?callable $keep = null): array
    {
        $rows = [];

        $query = SiteDayCrawler::query()->whereIn('site_day_id', $this->period->verified()->pluck('id'));

        if ($group !== null) {
            $query->where('group', $group);
        }

        foreach ($query->get() as $crawler) {
            if ($keep !== null && ! $keep($crawler->name)) {
                continue;
            }

            $key = $crawler->group->value."\0".$crawler->name;
            $row = $rows[$key] ?? ['group' => $crawler->group, 'name' => $crawler->name, 'requests' => 0, 'bytes' => 0, 'time_ms' => 0, 'timed' => 0, 'times' => [],
                'errors' => 0, 'paths' => 0, 'last_seen_at' => $crawler->last_seen_at];

            foreach (['requests', 'bytes', 'time_ms', 'timed', 'errors', 'paths'] as $figure) {
                $row[$figure] += $crawler->{$figure};
            }

            foreach ($crawler->times as $bucket => $count) {
                $row['times'][$bucket] = ($row['times'][$bucket] ?? 0) + $count;
            }

            $row['last_seen_at'] = $row['last_seen_at']->max($crawler->last_seen_at);
            $rows[$key] = $row;
        }

        $rows = array_map(fn (array $row) => [
            ...array_diff_key($row, ['time_ms' => 0, 'timed' => 0, 'times' => 0]),
            'mean_ms' => $row['timed'] > 0 ? intdiv($row['time_ms'], $row['timed']) : null,
            'p90_ms' => self::quantile($row['times'], 0.9),
        ], array_values($rows));

        usort($rows, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);

        return $rows;
    }

    /**
     * What bots fetched most: each path with its requests and the bots that
     * asked for it, busiest first; one bot's paths only when given.
     *
     * @return list<array{path: string, requests: int, bots: array<string, int>}>
     */
    public function botPaths(?string $bot = null, int $rows = 500): array
    {
        $paths = [];

        foreach ($this->split(CountKind::BotPath) as $name => $counts) {
            if ($bot !== null && $name !== $bot) {
                continue;
            }

            foreach ($counts as $path => $count) {
                if ($path === '') {
                    continue;
                }

                $paths[$path] ??= ['path' => (string) $path, 'requests' => 0, 'bots' => []];
                $paths[$path]['requests'] += $count;
                $paths[$path]['bots'][$name] = ($paths[$path]['bots'][$name] ?? 0) + $count;
            }
        }

        $paths = array_values($paths);
        usort($paths, fn (array $a, array $b) => [$b['requests'], $a['path']] <=> [$a['requests'], $b['path']]);

        return array_map(function (array $row) {
            arsort($row['bots']);

            return $row;
        }, array_slice($paths, 0, $rows));
    }

    /**
     * Each name's count of a "name\tsomething" breakdown, split back: [name][something] => count.
     *
     * @return array<string, array<string, int>>
     */
    public function split(CountKind $kind): array
    {
        $split = [];

        foreach ($this->period->counts($kind) as $key => $count) {
            [$name, $rest] = array_pad(explode(CrawlerCollector::SEPARATOR, (string) $key, 2), 2, '');
            $split[$name][$rest] = ($split[$name][$rest] ?? 0) + $count;
        }

        return $split;
    }

    /**
     * The value under which the given share of a histogram (value => count) lies.
     *
     * @param  array<string, int>  $histogram
     */
    public static function quantile(array $histogram, float $share): ?int
    {
        $total = array_sum($histogram);

        if ($total === 0) {
            return null;
        }

        ksort($histogram, SORT_NUMERIC);
        $target = (int) ceil($total * $share);
        $seen = 0;

        foreach ($histogram as $value => $count) {
            $seen += $count;

            if ($seen >= $target) {
                return (int) $value;
            }
        }

        return null;
    }
}
