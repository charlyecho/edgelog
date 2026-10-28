<?php

namespace App\Analytics;

use App\Analytics\Server\ServerRange;
use App\Enums\DayStatus;
use App\Models\IngestStatus;
use App\Models\Site;
use App\Models\SiteMetricMinute;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * What is wrong with each site right now, for the list of sites: a log that
 * stopped coming or was refused, a daily count late or withheld, a server
 * gone quiet, busy, short of memory or of disk. A few queries for every site.
 */
final class SiteAlerts
{
    /**
     * After this many minutes without a batch, the log is late; after
     * LOG_DOWN_AFTER, it has stopped.
     */
    public const int LOG_LATE_AFTER = 15;

    public const int LOG_DOWN_AFTER = 120;

    /**
     * After this many hours, yesterday should have been counted.
     */
    public const int COUNT_LATE_AFTER = 26;

    /**
     * How many days back a withheld day is still worth a word.
     */
    public const int WITHHELD_DAYS = 7;

    /**
     * The minutes the processors are averaged over.
     */
    public const int CPU_MINUTES = 5;

    /**
     * A share of processors, memory or disk, in percent, from which it is worth a look, then a problem.
     */
    public const float WARN_SHARE = 75;

    public const float BAD_SHARE = 90;

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, list<array{tone: string, label: string, detail: string}>> by site id, worst first
     */
    public static function for(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $ids = $sites->pluck('id');
        $statuses = IngestStatus::query()->whereIn('site_id', $ids)->get()->keyBy('site_id');
        $lastCounts = DB::table('site_days')->whereIn('site_id', $ids)->groupBy('site_id')->pluck(DB::raw('max(collected_at)'), 'site_id');
        $withheld = DB::table('site_days')
            ->whereIn('site_id', $ids)
            ->where('status', DayStatus::Withheld)
            ->where('day', '>=', now()->subDays(self::WITHHELD_DAYS + 1)->toDateString())
            ->groupBy('site_id')
            ->pluck(DB::raw('count(*)'), 'site_id');
        $latest = SiteMetricMinute::query()
            ->select('site_metric_minutes.*')
            ->joinSub(
                SiteMetricMinute::query()->whereIn('site_id', $ids)->groupBy('site_id')->select('site_id')->selectRaw('max(minute) as last_minute'),
                'last',
                fn ($join) => $join->on('last.site_id', '=', 'site_metric_minutes.site_id')->on('last.last_minute', '=', 'site_metric_minutes.minute'),
            )
            ->get()
            ->keyBy('site_id');
        $cpu = SiteMetricMinute::query()
            ->whereIn('site_id', $ids)
            ->where('minute', '>=', now()->subMinutes(self::CPU_MINUTES))
            ->whereNotNull('cpu')
            ->groupBy('site_id')
            ->pluck(DB::raw('avg(cpu)'), 'site_id');

        return $sites->mapWithKeys(fn (Site $site) => [$site->id => collect([
            ...self::log($statuses->get($site->id)),
            ...self::count($statuses->get($site->id), $lastCounts->get($site->id), (int) $withheld->get($site->id, 0)),
            ...self::server($latest->get($site->id), $cpu->has($site->id) ? (float) $cpu->get($site->id) : null),
        ])->sortBy(fn (array $alert) => ['bad' => 0, 'warn' => 1, 'muted' => 2][$alert['tone']])->values()->all()])->all();
    }

    /**
     * The log: never sent, refused, late or stopped.
     *
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function log(?IngestStatus $status): array
    {
        if ($status?->last_batch_at === null) {
            return [self::alert('muted', __('No log yet'), __('No line was received from edgelog-ship yet.'))];
        }

        $alerts = [];

        if ($status->isRefusing()) {
            $alerts[] = self::alert('bad', __('Log refused'), __('The last batch was refused :when: :reason', ['when' => $status->last_refusal_at->diffForHumans(), 'reason' => $status->last_refusal_reason?->words()]));
        }

        $minutes = $status->last_batch_at->diffInMinutes(now());

        if ($minutes > self::LOG_LATE_AFTER) {
            $alerts[] = self::alert(
                $minutes > self::LOG_DOWN_AFTER ? 'bad' : 'warn',
                __('No log for :time', ['time' => self::since($status->last_batch_at)]),
                __('The last batch came :when. Is edgelog-ship still running?', ['when' => $status->last_batch_at->diffForHumans()]),
            );
        }

        return $alerts;
    }

    /**
     * The daily count: late, or a day withheld lately.
     *
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function count(?IngestStatus $status, ?string $lastCount, int $withheld): array
    {
        $alerts = [];

        if ($withheld > 0) {
            $alerts[] = self::alert('bad', trans_choice(':count day withheld|:count days withheld', $withheld), trans_choice(':count day of the last :days was withheld: its two counts disagree.|:count days of the last :days were withheld: their two counts disagree.', $withheld, ['days' => self::WITHHELD_DAYS]));
        }

        $countedAt = $lastCount === null ? null : now()->parse($lastCount);
        $logsSince = $status?->last_batch_at;

        if ($logsSince !== null && ($countedAt ?? $logsSince)->lessThan(now()->subHours(self::COUNT_LATE_AFTER))) {
            $alerts[] = self::alert('warn', __('Daily count late'), $countedAt === null
                ? __('No day has been counted yet, though the log came :when.', ['when' => $logsSince->diffForHumans()])
                : __('The last day was counted :when.', ['when' => $countedAt->diffForHumans()]));
        }

        return $alerts;
    }

    /**
     * The server, from its last sample: quiet, or busy, short of memory or of disk.
     *
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function server(?SiteMetricMinute $latest, ?float $cpu): array
    {
        if ($latest === null) {
            return [];
        }

        if ($latest->minute->diffInSeconds(now()) > ServerRange::SILENT_AFTER) {
            return [self::alert('warn', __('Server quiet for :time', ['time' => self::since($latest->minute)]), __('No figures from the server since :when. Is edgelog-ship still running, with --metrics?', ['when' => $latest->minute->diffForHumans()]))];
        }

        $alerts = [];
        $percent = fn (float $value) => Number::format($value, precision: 0).'%';

        if ($cpu !== null && ($tone = self::shareTone($cpu)) !== null) {
            $alerts[] = self::alert($tone, __('CPU :share', ['share' => $percent($cpu)]), trans_choice('Busy share of :count core over the last :minutes minutes.|Busy share of all :count cores over the last :minutes minutes.', $latest->cores, ['minutes' => self::CPU_MINUTES]));
        }

        if ($latest->load5 > $latest->cores) {
            $alerts[] = self::alert('bad', __('Load :load', ['load' => Number::format($latest->load5, precision: 1)]), trans_choice('Load over 5 minutes above the :count core: work waits for a processor.|Load over 5 minutes above the :count cores: work waits for a processor.', $latest->cores));
        }

        $memory = $latest->memory_total > 0 ? $latest->memoryUsed() / $latest->memory_total * 100 : null;

        if ($memory !== null && ($tone = self::shareTone($memory)) !== null) {
            $alerts[] = self::alert($tone, __('Memory :share', ['share' => $percent($memory)]), __(':used of :total in use.', ['used' => Number::fileSize($latest->memoryUsed(), maxPrecision: 1), 'total' => Number::fileSize($latest->memory_total, maxPrecision: 1)]));
        }

        foreach ($latest->disks as $disk) {
            $whole = $disk['used'] + $disk['free'];
            $share = $whole > 0 ? $disk['used'] / $whole * 100 : null;

            if ($share !== null && ($tone = self::shareTone($share)) !== null) {
                $alerts[] = self::alert($tone, __('Disk :mount :share', ['mount' => $disk['mount'], 'share' => $percent($share)]), __(':free free on :mount.', ['free' => Number::fileSize($disk['free'], maxPrecision: 1), 'mount' => $disk['mount']]));
            }
        }

        return $alerts;
    }

    private static function shareTone(float $share): ?string
    {
        return match (true) {
            $share >= self::BAD_SHARE => 'bad',
            $share >= self::WARN_SHARE => 'warn',
            default => null,
        };
    }

    /**
     * The time since a moment, short: "3h", "2d".
     */
    private static function since(CarbonInterface $time): string
    {
        return $time->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }

    /**
     * @return array{tone: string, label: string, detail: string}
     */
    private static function alert(string $tone, string $label, string $detail): array
    {
        return ['tone' => $tone, 'label' => $label, 'detail' => $detail];
    }
}
