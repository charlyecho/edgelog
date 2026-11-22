<?php

namespace App\Analytics;

use App\Analytics\Server\ServerRange;
use App\Analytics\Stack\ServerSoftware;
use App\Models\Server;
use App\Models\ServerMetricMinute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * What is wrong with each server right now, from its last sample: gone
 * quiet, busy, short of memory or of disk; from its last minutes: PHP-FPM
 * full, requests waiting for a worker; and what runs on it: a system or a
 * branch no longer supported, known flaws an update fixes. Three queries
 * for every server, a fourth to name the pools when PHP-FPM is in trouble.
 */
final class ServerAlerts
{
    /**
     * The minutes the processors are averaged over.
     */
    public const int CPU_MINUTES = 5;

    /**
     * The minutes PHP-FPM's pools are looked at over.
     */
    public const int FPM_MINUTES = 15;

    /**
     * A share of processors, memory or disk, in percent, from which it is worth a look, then a problem.
     */
    public const float WARN_SHARE = 75;

    public const float BAD_SHARE = 90;

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<int, list<array{tone: string, label: string, detail: string}>> by server id, worst first
     */
    public static function for(Collection $servers): array
    {
        if ($servers->isEmpty()) {
            return [];
        }

        $ids = $servers->pluck('id');
        $latest = ServerMetricMinute::query()
            ->select('server_metric_minutes.*')
            ->joinSub(
                ServerMetricMinute::query()->whereIn('server_id', $ids)->groupBy('server_id')->select('server_id')->selectRaw('max(minute) as last_minute'),
                'last',
                fn ($join) => $join->on('last.server_id', '=', 'server_metric_minutes.server_id')->on('last.last_minute', '=', 'server_metric_minutes.minute'),
            )
            ->get()
            ->keyBy('server_id');
        $cpu = ServerMetricMinute::query()
            ->whereIn('server_id', $ids)
            ->where('minute', '>=', now()->subMinutes(self::CPU_MINUTES))
            ->whereNotNull('cpu')
            ->groupBy('server_id')
            ->pluck(DB::raw('avg(cpu)'), 'server_id');
        $fpm = ServerMetricMinute::query()
            ->whereIn('server_id', $ids)
            ->where('minute', '>=', now()->subMinutes(self::FPM_MINUTES))
            ->whereNotNull('fpm_active')
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('sum(fpm_saturated) as saturated, max(fpm_queue) as queue')
            ->get()
            ->keyBy('server_id');
        $troubled = $fpm->filter(fn (ServerMetricMinute $minutes) => $minutes->saturated > 0 || $minutes->queue > 0)->keys();
        $pools = $troubled->isEmpty() ? collect() : ServerMetricMinute::query()
            ->whereIn('server_id', $troubled)
            ->where('minute', '>=', now()->subMinutes(self::FPM_MINUTES))
            ->whereNotNull('fpm_pools')
            ->get(['server_id', 'fpm_pools'])
            ->groupBy('server_id');

        return $servers->mapWithKeys(fn (Server $server) => [$server->id => SiteAlerts::worstFirst([
            ...self::alerts($latest->get($server->id), $cpu->has($server->id) ? (float) $cpu->get($server->id) : null),
            ...self::fpmAlerts($fpm->get($server->id), $pools->get($server->id, collect())),
            ...ServerSoftware::alerts($server->software),
        ])])->all();
    }

    /**
     * The tone of a share of processors, memory or disk, in percent: none while it is fine.
     */
    public static function shareTone(?float $share): ?string
    {
        return match (true) {
            $share === null => null,
            $share >= self::BAD_SHARE => 'bad',
            $share >= self::WARN_SHARE => 'warn',
            default => null,
        };
    }

    /**
     * PHP-FPM over its last minutes: it reached pm.max_children, or
     * requests waited for a worker all the same; named after the pools
     * where it happened, when the shipper tells them apart.
     *
     * @param  Collection<int, ServerMetricMinute>  $poolMinutes  the minutes that tell the pools apart
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function fpmAlerts(?ServerMetricMinute $minutes, Collection $poolMinutes): array
    {
        if ($minutes === null) {
            return [];
        }

        $saturated = (int) $minutes->saturated;
        $queue = (int) $minutes->queue;
        $where = function (string $figure) use ($poolMinutes): string {
            $names = $poolMinutes
                ->flatMap(fn (ServerMetricMinute $minute) => collect($minute->fpm_pools)->filter(fn (array $pool) => ($pool[$figure] ?? 0) > 0)->keys())
                ->unique()
                ->sort()
                ->values();

            return $names->isEmpty() ? '' : ' ('.$names->implode(', ').')';
        };

        if ($saturated > 0) {
            return [SiteAlerts::alert('bad', __('PHP-FPM full').$where('saturated'), trans_choice('pm.max_children reached :count time over the last :minutes minutes: requests waited for a worker. Raise it if memory allows.|pm.max_children reached :count times over the last :minutes minutes: requests waited for a worker. Raise it if memory allows.', $saturated, ['minutes' => self::FPM_MINUTES]))];
        }

        if ($queue > 0) {
            return [SiteAlerts::alert('warn', __('Requests waiting for PHP').$where('queue'), trans_choice('Up to :count request waited for a free PHP-FPM worker over the last :minutes minutes.|Up to :count requests waited for a free PHP-FPM worker over the last :minutes minutes.', $queue, ['minutes' => self::FPM_MINUTES]))];
        }

        return [];
    }

    /**
     * The server, from its last sample: quiet, or busy, short of memory or of disk.
     *
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function alerts(?ServerMetricMinute $latest, ?float $cpu): array
    {
        if ($latest === null) {
            return [SiteAlerts::alert('muted', __('No figures yet'), __('No sample was received from edgelog-ship --metrics yet.'))];
        }

        if ($latest->minute->diffInSeconds(now()) > ServerRange::SILENT_AFTER) {
            return [SiteAlerts::alert('warn', __('Server quiet for :time', ['time' => SiteAlerts::since($latest->minute)]), __('No figures from the server since :when. Is edgelog-ship still running, with --metrics?', ['when' => $latest->minute->diffForHumans()]))];
        }

        $alerts = [];
        $percent = fn (float $value) => Number::format($value, precision: 0).'%';

        if ($cpu !== null && ($tone = self::shareTone($cpu)) !== null) {
            $alerts[] = SiteAlerts::alert($tone, __('CPU :share', ['share' => $percent($cpu)]), trans_choice('Busy share of :count core over the last :minutes minutes.|Busy share of all :count cores over the last :minutes minutes.', $latest->cores, ['minutes' => self::CPU_MINUTES]));
        }

        if ($latest->load5 > $latest->cores) {
            $alerts[] = SiteAlerts::alert('bad', __('Load :load', ['load' => Number::format($latest->load5, precision: 1)]), trans_choice('Load over 5 minutes above the :count core: work waits for a processor.|Load over 5 minutes above the :count cores: work waits for a processor.', $latest->cores));
        }

        $memory = $latest->memory_total > 0 ? $latest->memoryUsed() / $latest->memory_total * 100 : null;

        if (($tone = self::shareTone($memory)) !== null) {
            $alerts[] = SiteAlerts::alert($tone, __('Memory :share', ['share' => $percent($memory)]), __(':used of :total in use.', ['used' => Number::fileSize($latest->memoryUsed(), maxPrecision: 1), 'total' => Number::fileSize($latest->memory_total, maxPrecision: 1)]));
        }

        foreach ($latest->disks as $disk) {
            $whole = $disk['used'] + $disk['free'];
            $share = $whole > 0 ? $disk['used'] / $whole * 100 : null;

            if (($tone = self::shareTone($share)) !== null) {
                $alerts[] = SiteAlerts::alert($tone, __('Disk :mount :share', ['mount' => $disk['mount'], 'share' => $percent($share)]), __(':free free on :mount.', ['free' => Number::fileSize($disk['free'], maxPrecision: 1), 'mount' => $disk['mount']]));
            }
        }

        return $alerts;
    }
}
