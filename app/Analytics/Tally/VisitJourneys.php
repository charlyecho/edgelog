<?php

namespace App\Analytics\Tally;

use App\Analytics\DayFigures;
use App\Analytics\Patterns;
use App\Analytics\UserAgentLabels;
use App\Analytics\VisitorKey;
use App\Enums\CountKind;
use App\Ingest\LogHit;
use Carbon\CarbonImmutable;

/**
 * People's visits, one anonymous journey each: when it started, where it
 * came from, its pages in order, how long it lasted and where it ended.
 *
 * Visits are VisitCounter's, by its numbers, so the visits that read a
 * page are exactly the readers. A journey carries labels, pages and times,
 * never an address or a user agent; the keyed hash that tells a returning
 * visitor is kept apart from it.
 *
 * The length runs from the visit's first request to its last: a log sees
 * what was loaded, not how long someone read.
 */
final class VisitJourneys
{
    /**
     * Pages kept per journey; its counts are of all of them.
     */
    public const int TRAIL_KEEP = 50;

    /**
     * The arrow between the two pages of a step.
     */
    public const string ARROW = ' → ';

    /**
     * What engagement is split by: every visit has a source, a device and a
     * referrer; only a tagged visit has utm_ tags.
     */
    public const array DIMENSIONS = ['source', 'device', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * The dimensions every visit has one value of: each adds up to the visits.
     */
    public const array WHOLE_DIMENSIONS = ['source', 'device', 'referrer'];

    /**
     * @var array<int, array{start: int, end: int, pages: int, trail: list<string>, read: array<string, true>, last: ?string, steps: list<string>, not_found: bool, posted: bool, signed_in: bool, labels: ?array<string, mixed>, visitor: ?string}>
     */
    private array $visits = [];

    public function __construct(private readonly string $timezone = 'UTC') {}

    /**
     * A person's request, added to its visit (VisitCounter's number).
     *
     * @param  ?array{country: string, city: string, region: string, source: string, via: string, campaign: string, dims: array<string, string>}  $origin  where the visit came from, on its first page
     */
    public function add(int $visit, LogHit $hit, ?string $page, ?array $origin = null): void
    {
        $time = $hit->when->getTimestamp();
        $journey = $this->visits[$visit] ?? ['start' => $time, 'end' => $time, 'pages' => 0, 'trail' => [], 'read' => [], 'last' => null,
            'steps' => [], 'not_found' => false, 'posted' => false, 'signed_in' => false, 'labels' => null, 'visitor' => null];

        $journey['start'] = min($journey['start'], $time);
        $journey['end'] = max($journey['end'], $time);
        $journey['posted'] = $journey['posted'] || $hit->method === 'POST';
        $journey['not_found'] = $journey['not_found'] || ($hit->status === 404 && preg_match(Patterns::ASSET, $hit->path()) !== 1);
        $journey['signed_in'] = $journey['signed_in'] || $hit->userId !== '';

        if ($page !== null) {
            if ($journey['last'] !== null && $journey['last'] !== $page) {
                $journey['steps'][] = $journey['last'].self::ARROW.$page;
            }

            $journey['pages']++;
            $journey['last'] = $page;
            $journey['read'][$page] = true;

            if (count($journey['trail']) < self::TRAIL_KEEP) {
                $journey['trail'][] = $page;
            }
        }

        if ($origin !== null && $journey['labels'] === null) {
            $journey['labels'] = $origin + [
                'device' => UserAgentLabels::device($hit->userAgent),
                'browser' => UserAgentLabels::browser($hit->userAgent),
                'system' => UserAgentLabels::system($hit->userAgent),
            ];
            $journey['visitor'] = VisitorKey::of($hit->address, $hit->userAgent);
        }

        $this->visits[$visit] = $journey;
    }

    /**
     * Put the visits that read a page into the day's figures: the journeys,
     * their histograms, where they ended, their steps, the engagement by
     * source and device, and each reader's keyed hash.
     */
    public function finish(DayFigures $figures): void
    {
        $readers = array_filter($this->visits, fn (array $journey) => $journey['pages'] > 0 && $journey['labels'] !== null);
        uasort($readers, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        foreach ($readers as $journey) {
            $labels = $journey['labels'];
            $dimensions = ['source' => $labels['source'], 'device' => $labels['device'], ...$labels['dims']];
            unset($labels['dims'], $labels['region']);
            $seconds = $journey['end'] - $journey['start'];
            $bucket = (string) self::secondsBucket($seconds);

            $figures->count(CountKind::VisitPages, (string) $journey['pages']);
            $figures->count(CountKind::VisitSeconds, $bucket);
            $figures->count(CountKind::VisitExit, (string) $journey['last']);
            $figures->count(CountKind::VisitHour, (string) (int) CarbonImmutable::createFromTimestamp($journey['start'], $this->timezone)->format('G'));

            foreach (array_keys($journey['read']) as $page) {
                $figures->count(CountKind::PeoplePageVisitors, (string) $page);
            }

            foreach ($journey['steps'] as $step) {
                $figures->count(CountKind::VisitStep, $step);
            }

            foreach (['not_found', 'posted'] as $flag) {
                if ($journey[$flag]) {
                    $figures->count(CountKind::VisitFlag, $flag);
                }
            }

            foreach ($dimensions as $dimension => $name) {
                if ($name === '') {
                    continue;
                }

                $row = $figures->engagement[$dimension][$name] ?? ['visits' => 0, 'views' => 0, 'one_page' => 0, 'not_found' => 0, 'posted' => 0, 'seconds' => []];
                $row['visits']++;
                $row['views'] += $journey['pages'];
                $row['one_page'] += $journey['pages'] === 1 ? 1 : 0;
                $row['not_found'] += $journey['not_found'] ? 1 : 0;
                $row['posted'] += $journey['posted'] ? 1 : 0;
                $row['seconds'][$bucket] = ($row['seconds'][$bucket] ?? 0) + 1;
                $figures->engagement[$dimension][$name] = $row;
            }

            $visitor = $figures->visitors[$journey['visitor']] ?? ['visits' => 0, 'views' => 0, 'source' => $labels['source']];
            $visitor['visits']++;
            $visitor['views'] += $journey['pages'];
            $figures->visitors[$journey['visitor']] = $visitor;

            $figures->journeys[] = [
                'started_at' => $journey['start'],
                'seconds' => $seconds,
                'pages' => $journey['pages'],
                'trail' => $journey['trail'],
                'not_found' => $journey['not_found'],
                'posted' => $journey['posted'],
                'signed_in' => $journey['signed_in'],
                'returning' => null,
                'visitor' => $journey['visitor'],
                ...$labels,
            ];
        }
    }

    /**
     * A length kept to the second under a minute, to ten seconds under ten
     * minutes, to the minute under an hour, then to ten minutes: the median
     * over any period is read from these, so it is that precise.
     */
    public static function secondsBucket(int $seconds): int
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => $seconds,
            $seconds < 600 => $seconds - $seconds % 10,
            $seconds < 3600 => $seconds - $seconds % 60,
            default => $seconds - $seconds % 600,
        };
    }
}
