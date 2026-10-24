<?php

namespace App\Jobs;

use App\Analytics\Live\LiveCollector;
use App\Analytics\Live\LiveSnapshot;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Count today so far for the Real time tab, and keep it in the cache until the next refresh.
 */
class RefreshLiveSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    /**
     * A refresh that is already queued for the site makes this one useless.
     */
    public int $uniqueFor = 300;

    /**
     * How long a snapshot is shown before it is counted again, in seconds:
     * a little more than the two minutes between refreshes, so the page
     * counts it itself when no worker refreshed it.
     */
    public const int KEEP_SECONDS = 180;

    /**
     * The least time between two refreshes queued by arriving log lines, in seconds.
     */
    public const int AT_MOST_EVERY = 60;

    public function __construct(public Site $site) {}

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
        return "live-snapshot:{$site->id}";
    }

    /**
     * The site's latest snapshot, counted now when none is kept.
     */
    public static function latest(Site $site): LiveSnapshot
    {
        $kept = Cache::get(self::cacheKey($site));

        return is_array($kept) ? LiveSnapshot::fromArray($kept) : self::store($site, app(LiveCollector::class)->collect($site));
    }

    public function handle(LiveCollector $collector): void
    {
        self::store($this->site, $collector->collect($this->site));
    }

    private static function store(Site $site, LiveSnapshot $snapshot): LiveSnapshot
    {
        Cache::put(self::cacheKey($site), $snapshot->toArray(), self::KEEP_SECONDS);

        return $snapshot;
    }
}
