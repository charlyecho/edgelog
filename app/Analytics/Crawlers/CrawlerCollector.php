<?php

namespace App\Analytics\Crawlers;

use App\Analytics\Classification\Verdict;
use App\Analytics\DayFigures;
use App\Analytics\Patterns;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;

/**
 * What each crawler, assistant and bot of a day asked for and cost: its
 * requests, the bytes sent, its time per request, its failed requests,
 * its distinct paths and when it was last seen. People are left out.
 *
 * Assistants and search engines are also kept by path ("name\tpath"),
 * what robots.txt is judged against, and assistants by what they fetched:
 * a page, llms.txt, a Markdown or text file, robots.txt, a sitemap, or
 * another file. The address decides, not what the server answered.
 */
final class CrawlerCollector
{
    /**
     * The busiest names kept per group each day; the rest are one row.
     */
    public const int KEEP = 50;

    /**
     * The name scanner probes are kept under, whoever sent them.
     */
    public const string PROBES = '(scanner probes)';

    public const string SEPARATOR = "\t";

    /**
     * An answer that took this long is slow for a crawler.
     */
    public const int SLOW_MS = 1000;

    /**
     * A body this large is a large answer for a crawler.
     */
    public const int LARGE_BYTES = 1_000_000;

    /**
     * @var array<string, array<string, array{requests: int, bytes: int, time_ms: int, timed: int, times: array<string, int>, errors: int, paths: array<string, true>, last_seen: int}>>
     */
    private array $crawlers = [];

    /**
     * The day's breakdowns: [kind][name] => count.
     *
     * @var array<string, array<string, int>>
     */
    private array $counts = [];

    public function add(LogHit $hit, Verdict $verdict): void
    {
        if ($verdict->group === TrafficGroup::People) {
            return;
        }

        $group = $verdict->group->value;
        $name = $verdict->probe && $verdict->group === TrafficGroup::Bots ? self::PROBES : ($verdict->name ?: 'Unknown');
        $path = substr($hit->path(), 0, 200) ?: '/';
        $row = $this->crawlers[$group][$name] ?? ['requests' => 0, 'bytes' => 0, 'time_ms' => 0, 'timed' => 0, 'times' => [], 'errors' => 0, 'paths' => [], 'last_seen' => 0];

        $row['requests']++;
        $row['bytes'] += $hit->size ?? 0;
        $row['errors'] += $hit->status >= 400 ? 1 : 0;
        $row['paths'][$path] = true;
        $row['last_seen'] = max($row['last_seen'], $hit->when->getTimestamp());

        if ($hit->requestTime !== null) {
            $ms = (int) round($hit->requestTime * 1000);
            $bucket = (string) self::msBucket($ms);
            $row['time_ms'] += $ms;
            $row['timed']++;
            $row['times'][$bucket] = ($row['times'][$bucket] ?? 0) + 1;
        }

        $this->crawlers[$group][$name] = $row;

        if (in_array($verdict->group, [TrafficGroup::AnswerEngines, TrafficGroup::SearchEngines], true)) {
            $this->count(CountKind::CrawlerPath, $name.self::SEPARATOR.$path);

            foreach (self::issues($hit) as $issue) {
                $this->count(CountKind::CrawlerIssue, $name.self::SEPARATOR.$path.self::SEPARATOR.$issue);
            }
        }

        if ($verdict->group === TrafficGroup::AnswerEngines) {
            $this->count(CountKind::CrawlerFetch, $name.self::SEPARATOR.self::fetchKind($path));
        }
    }

    /**
     * Put each group's busiest names into the day's figures, the rest folded into one row.
     */
    public function finish(DayFigures $figures): void
    {
        foreach ($this->counts as $kind => $names) {
            foreach ($names as $name => $count) {
                $figures->count(CountKind::from($kind), (string) $name, $count);
            }
        }

        $rows = [];

        foreach ($this->crawlers as $group => $names) {
            uasort($names, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);
            $kept = array_slice($names, 0, self::KEEP, true);
            $rest = array_slice($names, self::KEEP, null, true);

            if ($rest !== []) {
                $kept[CountKind::OTHERS] = array_reduce($rest, fn (?array $sum, array $row) => $sum === null ? $row : [
                    'requests' => $sum['requests'] + $row['requests'],
                    'bytes' => $sum['bytes'] + $row['bytes'],
                    'time_ms' => $sum['time_ms'] + $row['time_ms'],
                    'timed' => $sum['timed'] + $row['timed'],
                    'times' => self::merge($sum['times'], $row['times']),
                    'errors' => $sum['errors'] + $row['errors'],
                    'paths' => $sum['paths'] + $row['paths'],
                    'last_seen' => max($sum['last_seen'], $row['last_seen']),
                ]);
            }

            foreach ($kept as $name => $row) {
                $rows[] = ['group' => $group, 'name' => (string) $name, ...$row, 'paths' => count($row['paths'])];
            }
        }

        $figures->crawlers = $rows;
    }

    /**
     * What went wrong for a crawler: gone (404 or 410), a server error, a
     * redirect, a slow answer, a large one.
     *
     * @return list<string>
     */
    public static function issues(LogHit $hit): array
    {
        return array_values(array_filter([
            in_array($hit->status, [404, 410], true) ? 'gone' : null,
            $hit->status >= 500 ? 'error' : null,
            $hit->status >= 300 && $hit->status < 400 && $hit->status !== 304 ? 'redirect' : null,
            $hit->requestTime !== null && $hit->requestTime * 1000 >= self::SLOW_MS ? 'slow' : null,
            ($hit->size ?? 0) >= self::LARGE_BYTES ? 'large' : null,
        ]));
    }

    /**
     * What a fetch asked for, by its address alone.
     */
    public static function fetchKind(string $path): string
    {
        $lower = strtolower($path);

        return match (true) {
            $lower === '/robots.txt' => 'robots',
            in_array($lower, ['/llms.txt', '/llms-full.txt'], true) => 'llms',
            preg_match('~sitemap[^/]*\.(?:xml|txt)(?:\.gz)?$~', $lower) === 1 => 'sitemap',
            preg_match('~\.(?:md|mdx|markdown|txt)$~', $lower) === 1 => 'markdown',
            preg_match(Patterns::ASSET, $path) === 1 => 'other',
            default => 'page',
        };
    }

    /**
     * A time kept to the millisecond under 100 ms, then to 10 ms under a
     * second, to 100 ms under ten seconds, then to the second.
     */
    public static function msBucket(int $ms): int
    {
        return match (true) {
            $ms < 100 => max(0, $ms),
            $ms < 1000 => $ms - $ms % 10,
            $ms < 10000 => $ms - $ms % 100,
            default => $ms - $ms % 1000,
        };
    }

    private function count(CountKind $kind, string $name): void
    {
        $this->counts[$kind->value][$name] = ($this->counts[$kind->value][$name] ?? 0) + 1;
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $count) {
            $a[$key] = ($a[$key] ?? 0) + $count;
        }

        return $a;
    }
}
