<?php

namespace App\Analytics;

use App\Enums\DayStatus;
use App\Models\IngestStatus;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is wrong with each site right now, for the list of sites: a log that
 * stopped coming or was refused, a daily count late or withheld. A few
 * queries for every site; its server's are ServerAlerts.
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

        return $sites->mapWithKeys(fn (Site $site) => [$site->id => self::worstFirst([
            ...self::log($statuses->get($site->id)),
            ...self::count($statuses->get($site->id), $lastCounts->get($site->id), (int) $withheld->get($site->id, 0)),
        ])])->all();
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
     * Alerts, the worst first.
     *
     * @param  list<array{tone: string, label: string, detail: string}>  $alerts
     * @return list<array{tone: string, label: string, detail: string}>
     */
    public static function worstFirst(array $alerts): array
    {
        return collect($alerts)->sortBy(fn (array $alert) => ['bad' => 0, 'warn' => 1, 'muted' => 2][$alert['tone']])->values()->all();
    }

    /**
     * The time since a moment, short: "3h", "2d".
     */
    public static function since(CarbonInterface $time): string
    {
        return $time->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true);
    }

    /**
     * @return array{tone: string, label: string, detail: string}
     */
    public static function alert(string $tone, string $label, string $detail): array
    {
        return ['tone' => $tone, 'label' => $label, 'detail' => $detail];
    }
}
