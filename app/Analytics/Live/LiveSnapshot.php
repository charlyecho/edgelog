<?php

namespace App\Analytics\Live;

use Carbon\CarbonImmutable;

/**
 * Today so far, as of one moment: what the Real time tab shows. Kept in the
 * cache between two refreshes; never written to the history.
 */
final class LiveSnapshot
{
    public int $activeNow = 0;

    public int $recentVisitors = 0;

    public int $visitorsToday = 0;

    public int $pagesRecent = 0;

    public int $bots = 0;

    public int $firstMinute = 0;

    /**
     * @var array<int, int>
     */
    public array $visitorsByMinute = [];

    /**
     * @var list<int>
     */
    public array $requestsByMinute = [];

    /**
     * @var array<string, array<string, int>>
     */
    public array $lists = [];

    /**
     * Visits today by country, and how many of them were active in the last 30 minutes.
     *
     * @var array<string, array{today: int, recent: int}>
     */
    public array $countries = [];

    /**
     * @var list<array{started: int, country: string, device: string, from: ?string, pages: int, landing: ?string, active: ?string}>
     */
    public array $newest = [];

    public function __construct(public CarbonImmutable $asOf, public string $timezone) {}

    /**
     * The snapshot as plain values, to keep in the cache: the cache unserializes
     * no PHP object, so a leaked application key cannot turn into a gadget chain.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf->getTimestamp(),
            'timezone' => $this->timezone,
            'active_now' => $this->activeNow,
            'recent_visitors' => $this->recentVisitors,
            'visitors_today' => $this->visitorsToday,
            'pages_recent' => $this->pagesRecent,
            'bots' => $this->bots,
            'first_minute' => $this->firstMinute,
            'visitors_by_minute' => $this->visitorsByMinute,
            'requests_by_minute' => $this->requestsByMinute,
            'lists' => $this->lists,
            'countries' => $this->countries,
            'newest' => $this->newest,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $snapshot = new self(CarbonImmutable::createFromTimestamp($values['as_of']), $values['timezone']);
        $snapshot->activeNow = $values['active_now'];
        $snapshot->recentVisitors = $values['recent_visitors'];
        $snapshot->visitorsToday = $values['visitors_today'];
        $snapshot->pagesRecent = $values['pages_recent'];
        $snapshot->bots = $values['bots'];
        $snapshot->firstMinute = $values['first_minute'];
        $snapshot->visitorsByMinute = $values['visitors_by_minute'];
        $snapshot->requestsByMinute = $values['requests_by_minute'];
        $snapshot->lists = $values['lists'];
        $snapshot->countries = $values['countries'];
        $snapshot->newest = $values['newest'];

        return $snapshot;
    }

    public function count(string $list, string $name): void
    {
        $this->lists[$list][$name] = ($this->lists[$list][$name] ?? 0) + 1;
    }

    public function countCountry(string $country, bool $recent): void
    {
        $this->countries[$country] ??= ['today' => 0, 'recent' => 0];
        $this->countries[$country]['today']++;
        $this->countries[$country]['recent'] += $recent ? 1 : 0;
    }

    /**
     * @return array<string, int>
     */
    public function list(string $name): array
    {
        return $this->lists[$name] ?? [];
    }

    /**
     * Sort every list, keep the ten newest visits.
     */
    public function finish(): void
    {
        foreach ($this->lists as &$list) {
            arsort($list);
        }

        uasort($this->countries, fn (array $a, array $b) => $b['today'] <=> $a['today']);
        usort($this->newest, fn (array $a, array $b) => $b['started'] <=> $a['started']);
        $this->newest = array_slice($this->newest, 0, 10);
    }
}
