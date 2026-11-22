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
     * @var list<string>
     */
    private array $pools = [];

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
     * theirs, in percent); the times PHP-FPM reached pm.max_children and its
     * slow requests added up; and the same for each pool told apart, under
     * poolKey(), null where a pool sent nothing.
     *
     * @return list<array{at: int, cpu: ?float, cpu_peak: ?float, iowait: ?float, load: ?float, load_peak: ?float, memory: ?float, memory_peak: ?float, swap: ?float, disk_read: ?int, disk_write: ?int, network_in: ?int, network_out: ?int, fpm_active: ?float, fpm_active_peak: ?int, fpm_total: ?int, fpm_queue: ?int, fpm_requests: ?float, fpm_saturated: ?int, fpm_slow: ?int, nginx_connections: ?float, nginx_connections_peak: ?int, nginx_requests: ?float}>
     */
    public function points(): array
    {
        if ($this->points !== null) {
            return $this->points;
        }

        $step = $this->step();
        $rows = $this->readsMinutes() ? $this->minuteRows() : $this->hourRows();
        $this->pools = $rows->flatMap(fn (array $row) => array_keys($row['pools']))->unique()->sort()->values()->all();
        $from = $this->from->getTimestamp();
        $buckets = $rows->groupBy(fn (array $row) => $from + intdiv($row['at'] - $from, $step) * $step);
        $points = [];

        for ($at = $from; $at < $this->until->getTimestamp(); $at += $step) {
            $bucket = $buckets->get($at);
            $points[] = ['at' => $at, ...($bucket === null ? self::emptyPoint($this->pools) : self::point($bucket, $this->pools))];
        }

        return $this->points = $points;
    }

    /**
     * The names of the PHP-FPM pools told apart in the range, in order.
     *
     * @return list<string>
     */
    public function pools(): array
    {
        $this->points();

        return $this->pools;
    }

    /**
     * The key of one pool's figure in the points: active, active_peak,
     * total, queue, requests or saturated.
     */
    public static function poolKey(string $figure, string $pool): string
    {
        return "pool_{$figure}:{$pool}";
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
     * One figure added up over the range, or null when no point has it.
     */
    public function total(string $key): int|float|null
    {
        $known = array_filter(array_column($this->points(), $key), fn ($value) => $value !== null);

        return $known === [] ? null : array_sum($known);
    }

    /**
     * Determine if any point of the range has the figure.
     */
    public function has(string $key): bool
    {
        return $this->total($key) !== null;
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
                'fpm_active' => $minute->fpm_active,
                'fpm_active_peak' => $minute->fpm_active,
                'fpm_total' => $minute->fpm_total,
                'fpm_queue' => $minute->fpm_queue,
                'fpm_requests' => $minute->fpm_requests,
                'fpm_saturated' => $minute->fpm_saturated,
                'fpm_slow' => $minute->fpm_slow,
                'nginx_connections' => $minute->nginx_connections,
                'nginx_connections_peak' => $minute->nginx_connections,
                'nginx_requests' => $minute->nginx_requests,
                'pools' => collect($minute->fpm_pools ?? [])->map(fn (array $pool) => [
                    'active' => $pool['active'],
                    'active_peak' => $pool['active'],
                    'total' => $pool['total'],
                    'queue' => $pool['queue'],
                    'requests' => $pool['requests'],
                    'saturated' => $pool['saturated'],
                ])->all(),
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
                'fpm_active' => $hour->fpm_active_average,
                'fpm_active_peak' => $hour->fpm_active_peak,
                'fpm_total' => $hour->fpm_total_peak,
                'fpm_queue' => $hour->fpm_queue_peak,
                'fpm_requests' => $hour->fpm_requests,
                'fpm_saturated' => $hour->fpm_saturated,
                'fpm_slow' => $hour->fpm_slow,
                'nginx_connections' => $hour->nginx_connections_average,
                'nginx_connections_peak' => $hour->nginx_connections_peak,
                'nginx_requests' => $hour->nginx_requests,
                'pools' => collect($hour->fpm_pools ?? [])->map(fn (array $pool) => [
                    'active' => $pool['active_average'],
                    'active_peak' => $pool['active_peak'],
                    'total' => $pool['total_peak'],
                    'queue' => $pool['queue_peak'],
                    'requests' => $pool['requests'],
                    'saturated' => $pool['saturated'],
                ])->all(),
            ]);
    }

    /**
     * One point from the samples that fell in it, with each pool's figures.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $pools
     * @return array<string, int|float|null>
     */
    private static function point(Collection $rows, array $pools): array
    {
        $average = function (string $key, int $precision = 1) use ($rows): ?float {
            $known = $rows->pluck($key)->filter(fn ($value) => $value !== null);

            return $known->isEmpty() ? null : round($known->avg(), $precision);
        };
        $peak = fn (string $key): int|float|null => $rows->pluck($key)->filter(fn ($value) => $value !== null)->max();
        $rate = fn (string $key): ?int => ($value = $average($key)) === null ? null : (int) round($value);
        $sum = function (string $key) use ($rows): ?int {
            $known = $rows->pluck($key)->filter(fn ($value) => $value !== null);

            return $known->isEmpty() ? null : (int) $known->sum();
        };

        $byPool = [];

        foreach ($pools as $pool) {
            $samples = $rows->map(fn (array $row) => $row['pools'][$pool] ?? null)->filter();
            $known = fn (string $figure) => $samples->pluck($figure)->filter(fn ($value) => $value !== null);
            $byPool[self::poolKey('active', $pool)] = $known('active')->isEmpty() ? null : round($known('active')->avg(), 1);
            $byPool[self::poolKey('active_peak', $pool)] = $known('active_peak')->max();
            $byPool[self::poolKey('total', $pool)] = $known('total')->max();
            $byPool[self::poolKey('queue', $pool)] = $known('queue')->max();
            $byPool[self::poolKey('requests', $pool)] = $known('requests')->isEmpty() ? null : round($known('requests')->avg(), 2);
            $byPool[self::poolKey('saturated', $pool)] = $known('saturated')->isEmpty() ? null : (int) $known('saturated')->sum();
        }

        return [
            ...$byPool,
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
            'fpm_active' => $average('fpm_active'),
            'fpm_active_peak' => $peak('fpm_active_peak'),
            'fpm_total' => $peak('fpm_total'),
            'fpm_queue' => $peak('fpm_queue'),
            'fpm_requests' => $average('fpm_requests', 2),
            'fpm_saturated' => $sum('fpm_saturated'),
            'fpm_slow' => $sum('fpm_slow'),
            'nginx_connections' => $average('nginx_connections'),
            'nginx_connections_peak' => $peak('nginx_connections_peak'),
            'nginx_requests' => $average('nginx_requests', 2),
        ];
    }

    /**
     * @param  list<string>  $pools
     * @return array<string, null>
     */
    private static function emptyPoint(array $pools): array
    {
        $byPool = collect($pools)->crossJoin(['active', 'active_peak', 'total', 'queue', 'requests', 'saturated'])
            ->mapWithKeys(fn (array $pair) => [self::poolKey($pair[1], $pair[0]) => null])
            ->all();

        return $byPool + array_fill_keys(['cpu', 'cpu_peak', 'iowait', 'load', 'load_peak', 'memory', 'memory_peak', 'swap', 'disk_read', 'disk_write', 'network_in', 'network_out',
            'fpm_active', 'fpm_active_peak', 'fpm_total', 'fpm_queue', 'fpm_requests', 'fpm_saturated', 'fpm_slow', 'nginx_connections', 'nginx_connections_peak', 'nginx_requests'], null);
    }

    /**
     * A part of a whole, in percent; null when there is no whole (no swap).
     */
    private static function share(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
