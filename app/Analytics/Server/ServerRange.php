<?php

namespace App\Analytics\Server;

use App\Analytics\SitePeriod;
use App\Models\Server;
use App\Models\ServerMetricHour;
use App\Models\ServerMetricMinute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A server over the range the page picked: its last minute, and its
 * figures drawn as at most a few hundred points; nothing for a site that
 * has no server yet. Up to a day is read from
 * the minutes, further from the hours; a point without any sample stays
 * empty, never drawn as zero.
 */
final class ServerRange
{
    /**
     * Each range: how far back, in seconds, and the seconds one point covers.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const array RANGES = [
        '6h' => [6 * 3600, 60],
        '24h' => [86400, 300],
        '7d' => [7 * 86400, 3600],
        '30d' => [30 * 86400, 3 * 3600],
        '90d' => [90 * 86400, 12 * 3600],
        '1y' => [365 * 86400, 86400],
    ];

    /**
     * The default range.
     */
    public const string DEFAULT = '24h';

    /**
     * After this many seconds without a sample, the server is said to be silent.
     */
    public const int SILENT_AFTER = 180;

    private ?ServerMetricMinute $latest = null;

    private bool $latestRead = false;

    /**
     * @var ?list<array<string, mixed>>
     */
    private ?array $points = null;

    /**
     * @param  ?string  $range  the range picked on the server tab, if it was picked there
     */
    private function __construct(
        public readonly ?Server $server,
        public readonly ?string $range,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        private readonly int $step,
    ) {}

    /**
     * One of the RANGES, up to now.
     */
    public static function last(?Server $server, string $range, ?CarbonImmutable $now = null): self
    {
        [$span, $step] = self::RANGES[$range];
        $now ??= CarbonImmutable::now();
        $until = $now->setTimestamp(intdiv($now->getTimestamp(), $step) * $step + $step);

        return new self($server, $range, $until->subSeconds($span), $until, $step);
    }

    /**
     * A site's server over the days of a period of the overview, from the
     * first's midnight to the midnight after the last, where the site is;
     * points as wide as those of the range that spans it (a day of 25 hours
     * still a day), and no further back than the hours are kept.
     */
    public static function overPeriod(?Server $server, SitePeriod $period): self
    {
        $until = $period->last->addDay()->startOfDay()->utc();
        $from = $period->first->startOfDay()->utc()->max($until->subDays(config('ingest.metrics_hour_days')));
        $span = $until->getTimestamp() - $from->getTimestamp();
        $step = collect(self::RANGES)->first(fn (array $range) => $span <= $range[0] + 3600, last(self::RANGES))[1];

        return new self($server, null, $from, $until, $step);
    }

    /**
     * The range the address asks for, or the default one.
     */
    public static function choose(?string $range): string
    {
        return array_key_exists((string) $range, self::RANGES) ? $range : self::DEFAULT;
    }

    /**
     * The seconds one point covers.
     */
    public function step(): int
    {
        return $this->step;
    }

    /**
     * Determine if the range is read from the minutes.
     */
    public function readsMinutes(): bool
    {
        return $this->step < 3600;
    }

    /**
     * The last minute the server sent, whatever the range.
     */
    public function latest(): ?ServerMetricMinute
    {
        if (! $this->latestRead) {
            $this->latest = $this->server?->metricMinutes()->latest('minute')->first();
            $this->latestRead = true;
        }

        return $this->latest;
    }

    /**
     * Determine if the last sample is older than SILENT_AFTER.
     */
    public function isSilent(): bool
    {
        return $this->latest() !== null && $this->latest()->minute->diffInSeconds(now()) > self::SILENT_AFTER;
    }

    /**
     * Each point of the range, oldest first: its start and, when a sample
     * fell in it, the averages and peaks (memory and swap as a share of
     * theirs, in percent).
     *
     * @return list<array{at: int, cpu: ?float, cpu_peak: ?float, iowait: ?float, load: ?float, load_peak: ?float, memory: ?float, memory_peak: ?float, swap: ?float, disk_read: ?int, disk_write: ?int, network_in: ?int, network_out: ?int}>
     */
    public function points(): array
    {
        if ($this->points !== null) {
            return $this->points;
        }

        $step = $this->step();
        $rows = $this->readsMinutes() ? $this->minuteRows() : $this->hourRows();
        $from = $this->from->getTimestamp();
        $buckets = $rows->groupBy(fn (array $row) => $from + intdiv($row['at'] - $from, $step) * $step);
        $points = [];

        for ($at = $from; $at < $this->until->getTimestamp(); $at += $step) {
            $bucket = $buckets->get($at);
            $points[] = ['at' => $at, ...($bucket === null ? self::emptyPoint() : self::point($bucket))];
        }

        return $this->points = $points;
    }

    /**
     * The average of one figure over the points that have it.
     */
    public function average(string $key): ?float
    {
        $known = array_filter(array_column($this->points(), $key), fn ($value) => $value !== null);

        return $known === [] ? null : array_sum($known) / count($known);
    }

    /**
     * The highest of one figure over the range.
     */
    public function peak(string $key): int|float|null
    {
        $known = array_filter(array_column($this->points(), $key), fn ($value) => $value !== null);

        return $known === [] ? null : max($known);
    }

    /**
     * One figure for a sparkline: the points averaged in at most `$length`
     * runs, as few as a tile shows; null where no sample fell in a run.
     *
     * @return list<?float>
     */
    public function series(string $key, int $length = 30): array
    {
        $values = array_column($this->points(), $key);

        return array_map(function (array $run): ?float {
            $known = array_filter($run, fn ($value) => $value !== null);

            return $known === [] ? null : array_sum($known) / count($known);
        }, array_chunk($values, max(1, (int) ceil(count($values) / $length))));
    }

    /**
     * The cores of the last sample, drawn as a guide on the load chart.
     */
    public function cores(): ?int
    {
        return $this->latest()?->cores;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function minuteRows(): Collection
    {
        if ($this->server === null) {
            return collect();
        }

        return $this->server->metricMinutes()
            ->whereBetween('minute', [$this->from, $this->until])
            ->get()
            ->map(fn (ServerMetricMinute $minute) => [
                'at' => $minute->minute->getTimestamp(),
                'cpu' => $minute->cpu,
                'cpu_peak' => $minute->cpu,
                'iowait' => $minute->iowait,
                'load' => $minute->load1,
                'load_peak' => $minute->load1,
                'memory' => self::share($minute->memoryUsed(), $minute->memory_total),
                'memory_peak' => self::share($minute->memoryUsed(), $minute->memory_total),
                'swap' => self::share($minute->swapUsed(), $minute->swap_total),
                'disk_read' => $minute->disk_read,
                'disk_write' => $minute->disk_write,
                'network_in' => $minute->network_in,
                'network_out' => $minute->network_out,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function hourRows(): Collection
    {
        if ($this->server === null) {
            return collect();
        }

        return $this->server->metricHours()
            ->whereBetween('hour', [$this->from, $this->until])
            ->get()
            ->map(fn (ServerMetricHour $hour) => [
                'at' => $hour->hour->getTimestamp(),
                'cpu' => $hour->cpu_average,
                'cpu_peak' => $hour->cpu_peak,
                'iowait' => $hour->iowait_average,
                'load' => $hour->load_average,
                'load_peak' => $hour->load_peak,
                'memory' => self::share($hour->memory_used_average, $hour->memory_total),
                'memory_peak' => self::share($hour->memory_used_peak, $hour->memory_total),
                'swap' => self::share($hour->swap_used_peak, $hour->swap_total),
                'disk_read' => $hour->disk_read,
                'disk_write' => $hour->disk_write,
                'network_in' => $hour->network_in,
                'network_out' => $hour->network_out,
            ]);
    }

    /**
     * One point from the samples that fell in it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private static function point(Collection $rows): array
    {
        $average = function (string $key, int $precision = 1) use ($rows): ?float {
            $known = $rows->pluck($key)->filter(fn ($value) => $value !== null);

            return $known->isEmpty() ? null : round($known->avg(), $precision);
        };
        $peak = fn (string $key): int|float|null => $rows->pluck($key)->filter(fn ($value) => $value !== null)->max();
        $rate = fn (string $key): ?int => ($value = $average($key)) === null ? null : (int) round($value);

        return [
            'cpu' => $average('cpu'),
            'cpu_peak' => $peak('cpu_peak'),
            'iowait' => $average('iowait'),
            'load' => $average('load', 2),
            'load_peak' => $peak('load_peak'),
            'memory' => $average('memory'),
            'memory_peak' => $peak('memory_peak'),
            'swap' => $peak('swap'),
            'disk_read' => $rate('disk_read'),
            'disk_write' => $rate('disk_write'),
            'network_in' => $rate('network_in'),
            'network_out' => $rate('network_out'),
        ];
    }

    /**
     * @return array<string, null>
     */
    private static function emptyPoint(): array
    {
        return array_fill_keys(['cpu', 'cpu_peak', 'iowait', 'load', 'load_peak', 'memory', 'memory_peak', 'swap', 'disk_read', 'disk_write', 'network_in', 'network_out'], null);
    }

    /**
     * A part of a whole, in percent; null when there is no whole (no swap).
     */
    private static function share(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
