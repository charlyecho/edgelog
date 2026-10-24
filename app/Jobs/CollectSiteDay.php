<?php

namespace App\Jobs;

use App\Analytics\DayChecks;
use App\Analytics\DayCollector;
use App\Analytics\DayWindow;
use App\Enums\DayStatus;
use App\Models\Site;
use App\Models\SiteDay;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Count one day of a site from its shipped log, check that its figures add
 * up, and keep them: "collected" until the audit recounts them, or
 * "withheld" when they do not add up.
 */
class CollectSiteDay implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 1800;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    public function __construct(public Site $site, public string $day) {}

    /**
     * The unique ID of the job: one count of a site's day at a time.
     */
    public function uniqueId(): string
    {
        return "{$this->site->id}:{$this->day}";
    }

    public function handle(DayCollector $collector): void
    {
        $window = DayWindow::for($this->site, $this->day);
        $figures = $collector->collect($this->site, $window);

        if ($figures === null) {
            return;
        }

        $problems = DayChecks::problems($figures);

        SiteDay::store(
            $this->site,
            $this->day,
            $window->timezone,
            $figures,
            $problems === [] ? DayStatus::Collected : DayStatus::Withheld,
            $problems,
        );
    }
}
