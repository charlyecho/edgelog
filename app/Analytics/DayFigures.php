<?php

namespace App\Analytics;

use App\Enums\CountKind;
use App\Enums\EngineTier;
use App\Enums\TrafficGroup;
use Carbon\CarbonImmutable;

/**
 * Every figure of one day of a site: the lines read, the five groups, and
 * the breakdowns, each adding up to what it breaks down.
 */
final class DayFigures
{
    /**
     * How many rows a breakdown keeps before the rest is folded.
     */
    public const int TOP = 100;

    public int $parsed = 0;

    public int $unparsed = 0;

    public int $hits = 0;

    public int $otherHosts = 0;

    public int $unfiltered = 0;

    public int $citedVisits = 0;

    /**
     * Signed-in people's visits that read a page.
     */
    public int $signedInReaders = 0;

    /**
     * Stylesheets asked for on the site's hosts. None on a busy day means
     * the web server leaves them out of its log: not a figure, only a
     * warning for the site's settings, so the audit does not recount it.
     */
    public int $stylesheets = 0;

    /**
     * Each signed-in user of the day: their page views, and where the day's
     * first visit they were signed in came from.
     *
     * @var array<string, array{views: int, source: ?string, referrer: ?string, landing: ?string, first_seen: CarbonImmutable}>
     */
    public array $users = [];

    /**
     * People's visits that read a page, oldest first, as VisitJourneys keeps them.
     *
     * @var list<array<string, mixed>>
     */
    public array $journeys = [];

    /**
     * The visits by source type, device, referrer and utm_ tag: [dimension][name] => row.
     *
     * @var array<string, array<string, array{visits: int, views: int, one_page: int, not_found: int, posted: int, seconds: array<string, int>}>>
     */
    public array $engagement = [];

    /**
     * Each reader of the day by keyed hash: their visits, the pages they
     * read, and where the first came from.
     *
     * @var array<string, array{visits: int, views: int, source: string}>
     */
    public array $visitors = [];

    /**
     * The day's pages read by a person or fetched by Google, for their publish dates.
     *
     * @var array<string, array{read: bool, google: bool}>
     */
    public array $pages = [];

    /**
     * The day's security (SecurityCollector): its figures, each host's
     * answers to probes, the possible exposures, the suspects and the episodes.
     *
     * @var array<string, mixed>
     */
    public array $security = [];

    /**
     * What each crawler, assistant and bot asked for and cost (CrawlerCollector).
     *
     * @var list<array<string, mixed>>
     */
    public array $crawlers = [];

    /**
     * The site's own heartbeat (HealthCollector): five-minute slots expected
     * and answered, beats sent and failed, and the gaps; null when none came.
     *
     * @var ?array{expected: int, up: int, beats: int, failed: int, gaps: list<array{from: int, to: int, minutes: int}>}
     */
    public ?array $heartbeat = null;

    /**
     * What can be seen of the log being whole (QualityCollector).
     *
     * @var ?array{duplicates: int, short: int, silences: list<array{from: int, to: int, minutes: int}>}
     */
    public ?array $quality = null;

    public ?CarbonImmutable $firstSeen = null;

    public ?CarbonImmutable $lastSeen = null;

    public ?CarbonImmutable $partialFrom = null;

    /**
     * @var array<string, GroupFigures>
     */
    public array $groups = [];

    /**
     * @var array<string, array<string, int>>
     */
    public array $counts = [];

    public function __construct()
    {
        foreach (TrafficGroup::cases() as $group) {
            $this->groups[$group->value] = new GroupFigures;
        }
    }

    public function group(TrafficGroup $group): GroupFigures
    {
        return $this->groups[$group->value];
    }

    public function count(CountKind $kind, string $name, int $by = 1): void
    {
        $this->counts[$kind->value][$name] = ($this->counts[$kind->value][$name] ?? 0) + $by;
    }

    /**
     * @return array<string, int>
     */
    public function countsOf(CountKind $kind): array
    {
        return $this->counts[$kind->value] ?? [];
    }

    /**
     * Keep the top rows of every breakdown and fold the rest into one, so
     * each still adds up to its figure.
     */
    public function fold(int $top = self::TOP): void
    {
        foreach ($this->counts as $kind => $rows) {
            if (in_array($kind, [...CountKind::REVENUE, ...CountKind::UNFOLDED], true)) {
                continue;
            }

            arsort($rows);
            $keep = CountKind::KEEP[$kind] ?? $top;

            if (count($rows) > $keep) {
                $kept = array_slice($rows, 0, $keep - 1, true);
                $kept[CountKind::OTHERS] = ($kept[CountKind::OTHERS] ?? 0) + array_sum($rows) - array_sum($kept);
                $rows = $kept;
            }

            $this->counts[$kind] = $rows;
        }
    }

    /**
     * The figures both roads count, flattened, for the audit to compare.
     *
     * @return array<string, int>
     */
    public function core(): array
    {
        $core = [
            'parsed' => $this->parsed,
            'hits' => $this->hits,
            'other_hosts' => $this->otherHosts,
            'cited_visits' => $this->citedVisits,
            'users.signed_in' => count($this->users),
            'people.signed_in_readers' => $this->signedInReaders,
        ];

        foreach ($this->groups as $group => $figures) {
            foreach ($figures->toArray() as $figure => $value) {
                $core["{$group}.{$figure}"] = $value;
            }
        }

        foreach (EngineTier::cases() as $tier) {
            $core["tier.{$tier->value}"] = $this->countsOf(CountKind::EngineTier)[$tier->value] ?? 0;
        }

        foreach (CountKind::REVENUE as $kind) {
            foreach ($this->counts[$kind] ?? [] as $currency => $value) {
                $core["{$kind}.{$currency}"] = $value;
            }
        }

        return $core;
    }
}
