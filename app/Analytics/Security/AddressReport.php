<?php

namespace App\Analytics\Security;

use App\Analytics\DayWindow;
use App\Analytics\LogWindowReader;
use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Models\SiteDayAttack;
use App\Models\SiteDaySuspect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything kept about one address in a period: its rows of each day (what
 * it looked for, what it was answered, its browsers, its attacks), and its
 * every request read again from the log, today's included, while the log's
 * files are still there.
 */
final class AddressReport
{
    /**
     * The most requests read back from the log; the newest are kept.
     */
    public const int MAX_REQUESTS = 500;

    /**
     * @var Collection<int, SiteDaySuspect>
     */
    public readonly Collection $rows;

    /**
     * @var Collection<int, SiteDayAttack>
     */
    public readonly Collection $attacks;

    /**
     * The newest requests, newest first.
     *
     * @var list<LogHit>
     */
    public array $requests = [];

    /**
     * Every request of the address the log still holds, of which $requests are the newest.
     */
    public int $logged = 0;

    /**
     * Each path the log shows it asked for: [path => [requests, answered by class]].
     *
     * @var array<string, array{0: int, 1: array<string, int>}>
     */
    public array $paths = [];

    public function __construct(public readonly SecurityPeriod $security, public readonly string $address)
    {
        $this->rows = $security->suspects()->where('address', $address)->get();
        $this->attacks = SiteDayAttack::query()->whereIn('site_day_id', $security->period->verified()->pluck('id'))
            ->where('address', $address)->orderByDesc('started_at')->get();

        $this->readLog();
    }

    public function country(): string
    {
        return (string) $this->rows->first()?->country;
    }

    /**
     * Its browsers, with the requests each made, most first.
     *
     * @return array<string, int>
     */
    public function userAgents(): array
    {
        $agents = [];

        foreach ($this->rows as $row) {
            $agents[$row->user_agent] = ($agents[$row->user_agent] ?? 0) + $row->requests;
        }

        foreach ($this->requests as $hit) {
            $agents[$hit->userAgent] ??= 0;
        }

        arsort($agents);

        return $agents;
    }

    /**
     * Summed over its rows: what it looked for, what it was answered, the paths kept per day.
     *
     * @return array<string, int>
     */
    public function sum(string $list): array
    {
        $sum = [];

        foreach ($this->rows as $row) {
            foreach ($row->{$list} as $name => $count) {
                $sum[$name] = ($sum[$name] ?? 0) + $count;
            }
        }

        arsort($sum);

        return $sum;
    }

    public function figure(string $name): int
    {
        return (int) $this->rows->sum($name);
    }

    /**
     * @return list<string>
     */
    public function signs(): array
    {
        return $this->rows->flatMap(fn (SiteDaySuspect $row) => $row->signs)->unique()->values()->all();
    }

    public function firstSeen(): ?CarbonImmutable
    {
        return $this->rows->min('first_seen_at') ?? (end($this->requests) ?: null)?->when;
    }

    public function daysSeen(): int
    {
        return $this->rows->pluck('site_day_id')->unique()->count();
    }

    /**
     * The address's requests from the period's first day up to now, read again from the log.
     */
    private function readLog(): void
    {
        $period = $this->security->period;
        $window = new DayWindow($period->first->toDateString(), $period->site->timezone, now()->toImmutable()->addSecond());
        $reader = new LogWindowReader(IngestLog::for($period->site)->folder(), $period->site->log_format->parser(), $window);

        foreach ($reader->hitsContaining($this->address) as $hit) {
            if ($hit->address !== $this->address) {
                continue;
            }

            $this->logged++;
            $this->requests[] = $hit;

            if (count($this->requests) > self::MAX_REQUESTS) {
                array_shift($this->requests);
            }

            $answered = intdiv($hit->status, 100).'xx';
            $path = substr($hit->url, 0, 300);
            $this->paths[$path][0] = ($this->paths[$path][0] ?? 0) + 1;
            $this->paths[$path][1][$answered] = ($this->paths[$path][1][$answered] ?? 0) + 1;
        }

        usort($this->requests, fn (LogHit $a, LogHit $b) => $b->when <=> $a->when);
        uasort($this->paths, fn (array $a, array $b) => $b[0] <=> $a[0]);
    }
}
