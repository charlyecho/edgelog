<?php

namespace App\Analytics\Live;

use App\Analytics\Classification\Verdict;
use App\Analytics\DayCollector;
use App\Analytics\DayWindow;
use App\Ingest\LogHit;
use App\Models\Site;

/**
 * Everything live about today, from one read of its log: the daily count's
 * own two passes (DayCollector) give each request and its verdict to the
 * Real time snapshot and to the request log too. Only when the request
 * log's hours reach back before midnight does it read the log on its own.
 */
final readonly class TodayCounter
{
    public function __construct(private DayCollector $days, private LiveCollector $live, private RecentRequests $requests) {}

    /**
     * Today's figures so far (null when the log holds nothing of today),
     * the Real time snapshot, and the last hours' requests.
     *
     * @return array{figures: ?array<string, mixed>, live: LiveSnapshot, requests: array<string, mixed>}
     */
    public function count(Site $site): array
    {
        $window = DayWindow::todaySoFar($site);
        $shared = RecentRequests::from()->greaterThanOrEqualTo($window->start);
        $live = $this->live->begin($site);
        $requests = $shared ? $this->requests->begin($site) : null;

        $figures = $this->days->collect($site, $window, function (LogHit $hit, Verdict $verdict) use ($live, $requests) {
            $live->add($hit, $verdict);
            $requests?->add($hit, $verdict);
        });

        return [
            'figures' => $figures?->summary(),
            'live' => $live->finish(),
            'requests' => $requests?->finish() ?? $this->requests->collect($site),
        ];
    }
}
