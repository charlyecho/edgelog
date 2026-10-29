<?php

namespace App\Analytics\Server;

use App\Models\ServerMetricHour;
use App\Models\ServerMetricMinute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

/**
 * Sum the server's minutes up into hours, then forget what is too old.
 *
 * Every hour that is over and was not summed yet is, from the last one
 * summed (again, in case a minute came in as it was) and at most as far back
 * as minutes are kept. Each hour keeps the average and the peak of the busy
 * share, the load and the memory in use, the peak of the swap, the average
 * bytes a second, and the disks as its last minute saw them.
 */
final class MetricRollup
{
    /**
     * Sum up the hours over by `$now`, and prune; the number of hours written.
     */
    public static function run(CarbonImmutable $now): int
    {
        $until = $now->startOfHour();
        $oldest = $now->subDays((int) config('ingest.metrics_minute_days'))->startOfHour();
        $last = ServerMetricHour::query()->max('hour');
        $from = $last === null ? $oldest : CarbonImmutable::parse($last)->max($oldest);
        $written = 0;

        ServerMetricMinute::query()
            ->where('minute', '>=', $from)
            ->where('minute', '<', $until)
            ->orderBy('server_id')
            ->orderBy('minute')
            ->with('server')
            ->lazy()
            ->chunkWhile(fn (ServerMetricMinute $minute, int $key, Enumerable $chunk) => $chunk->last()->server_id === $minute->server_id
                && $chunk->last()->minute->startOfHour()->equalTo($minute->minute->startOfHour()))
            ->each(function (Enumerable $minutes) use (&$written) {
                self::writeHour($minutes->collect());
                $written++;
            });

        ServerMetricMinute::query()->where('minute', '<', $oldest)->delete();
        ServerMetricHour::query()->where('hour', '<', $now->subDays((int) config('ingest.metrics_hour_days')))->delete();

        return $written;
    }

    /**
     * @param  Collection<int, ServerMetricMinute>  $minutes  one server's minutes of one hour
     */
    private static function writeHour(Collection $minutes): void
    {
        $last = $minutes->last();
        $memory = $minutes->map(fn (ServerMetricMinute $minute) => $minute->memoryUsed());

        $last->server->metricHours()->updateOrCreate(
            ['hour' => $last->minute->startOfHour()],
            [
                'samples' => $minutes->count(),
                'cpu_average' => self::average($minutes, 'cpu'),
                'cpu_peak' => $minutes->pluck('cpu')->filter(fn ($value) => $value !== null)->max(),
                'iowait_average' => self::average($minutes, 'iowait'),
                'load_average' => round($minutes->avg('load1'), 2),
                'load_peak' => $minutes->max('load1'),
                'cores' => $last->cores,
                'memory_total' => $last->memory_total,
                'memory_used_average' => (int) round($memory->avg()),
                'memory_used_peak' => $memory->max(),
                'swap_total' => $last->swap_total,
                'swap_used_peak' => $minutes->map(fn (ServerMetricMinute $minute) => $minute->swapUsed())->max(),
                'disk_read' => self::rate($minutes, 'disk_read'),
                'disk_write' => self::rate($minutes, 'disk_write'),
                'network_in' => self::rate($minutes, 'network_in'),
                'network_out' => self::rate($minutes, 'network_out'),
                'disks' => $last->disks,
            ],
        );
    }

    /**
     * The average of the minutes that have the figure, or null when none has.
     *
     * @param  Collection<int, ServerMetricMinute>  $minutes
     */
    private static function average(Collection $minutes, string $key): ?float
    {
        $known = $minutes->pluck($key)->filter(fn ($value) => $value !== null);

        return $known->isEmpty() ? null : round($known->avg(), 1);
    }

    /**
     * @param  Collection<int, ServerMetricMinute>  $minutes
     */
    private static function rate(Collection $minutes, string $key): ?int
    {
        $known = $minutes->pluck($key)->filter(fn ($value) => $value !== null);

        return $known->isEmpty() ? null : (int) round($known->avg());
    }
}
