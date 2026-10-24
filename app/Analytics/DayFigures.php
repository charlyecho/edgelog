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
     * Each signed-in user of the day: their page views, and where the day's
     * first visit they were signed in came from.
     *
     * @var array<string, array{views: int, source: ?string, referrer: ?string, landing: ?string, first_seen: CarbonImmutable}>
     */
    public array $users = [];

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
            if (in_array($kind, CountKind::REVENUE, true)) {
                continue;
            }

            arsort($rows);

            if (count($rows) > $top) {
                $kept = array_slice($rows, 0, $top - 1, true);
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
