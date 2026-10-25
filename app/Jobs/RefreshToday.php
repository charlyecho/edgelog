<?php

namespace App\Jobs;

use App\Analytics\Live\LiveSnapshot;
use App\Analytics\Live\TodayCounter;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Count today so far, once, for every live page: Overview's Real time and
 * Today, and Site's Requests. The pages only read what this kept in the
 * cache; they never count. Runs on the "live" queue, so a long count never
 * holds the daily counts and audits back.
 */
class RefreshToday implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'live';

    public int $timeout = 300;

    /**
     * A refresh that is already queued for the site makes this one useless.
     */
    public int $uniqueFor = 300;

    /**
     * How old a count may be before a page asks for a new one, in seconds:
     * a little more than the two minutes between scheduled refreshes.
     */
    public const int FRESH_SECONDS = 180;

    /**
     * How long a count stands when no line arrived since, in seconds: only
     * the clock moved ("active now" grows old), so it is counted again then.
     */
    public const int IDLE_SECONDS = 600;

    /**
     * The least time between two refreshes queued by arriving log lines, in seconds.
     */
    public const int AT_MOST_EVERY = 60;

    public function __construct(public Site $site)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Queue a refresh because new lines arrived, at most once a minute per
     * site, however many batches the shippers send.
     */
    public static function soon(Site $site): void
    {
        if (Cache::add("live-refresh-queued:{$site->id}", true, self::AT_MOST_EVERY)) {
            self::dispatch($site);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public static function cacheKey(Site $site): string
    {
        return "site-today:{$site->id}";
    }

    /**
     * The site's count of today as kept, or null when there is none for
     * today (yesterday's is never shown as today's).
     *
     * @return ?array{day: string, counted_at: int, batch_at: ?int, figures: ?array<string, mixed>, live: array<string, mixed>, requests: array<string, mixed>}
     */
    public static function kept(Site $site): ?array
    {
        $kept = Cache::get(self::cacheKey($site));

        return is_array($kept) && $kept['day'] === now($site->timezone)->toDateString() ? $kept : null;
    }

    /**
     * What a page shows: the count kept, with a new one queued when it is
     * old or missing. Null while the first count of the day is being made.
     *
     * @return ?array{day: string, counted_at: int, batch_at: ?int, figures: ?array<string, mixed>, live: array<string, mixed>, requests: array<string, mixed>}
     */
    public static function latest(Site $site): ?array
    {
        $kept = self::kept($site);

        if ($kept === null || $kept['counted_at'] < now()->getTimestamp() - self::FRESH_SECONDS) {
            self::dispatch($site);

            // A queue that runs at once (sync) has already counted it.
            return self::kept($site) ?? $kept;
        }

        return $kept;
    }

    /**
     * The Real time snapshot of a kept count.
     *
     * @param  array<string, mixed>  $today
     */
    public static function snapshot(array $today): LiveSnapshot
    {
        return LiveSnapshot::fromArray($today['live']);
    }

    public function handle(TodayCounter $counter): void
    {
        $kept = self::kept($this->site);
        $batchAt = $this->site->ingestStatus()->first()?->last_batch_at?->getTimestamp();

        if ($kept !== null && $kept['batch_at'] === $batchAt && $kept['counted_at'] >= now()->getTimestamp() - self::IDLE_SECONDS) {
            return;
        }

        $today = $counter->count($this->site);

        Cache::put(self::cacheKey($this->site), [
            'day' => now($this->site->timezone)->toDateString(),
            'counted_at' => now()->getTimestamp(),
            'batch_at' => $batchAt,
            'figures' => $today['figures'],
            'live' => $today['live']->toArray(),
            'requests' => $today['requests'],
        ], now()->addDay());
    }
}
