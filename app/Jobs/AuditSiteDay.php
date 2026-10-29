<?php

namespace App\Jobs;

use App\Analytics\Audit\Comparison;
use App\Analytics\Audit\Recount;
use App\Analytics\Audit\RevenueRecount;
use App\Analytics\DayWindow;
use App\Analytics\SiteHosts;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recount a collected day by another road and compare. When both agree the
 * day is verified; when they do not, it is withheld, never guessed.
 */
class AuditSiteDay implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public Site $site, public string $day) {}

    public function uniqueId(): string
    {
        return "{$this->site->id}:{$this->day}";
    }

    public function handle(): void
    {
        $siteDay = $this->site->days()->whereDate('day', $this->day)->with(['groups', 'counts'])->first();

        if ($siteDay === null || $siteDay->status !== DayStatus::Collected) {
            return;
        }

        $recount = new Recount(
            IngestLog::for($this->site)->folder(),
            $this->site->log_format->value,
            new DayWindow($this->day, $siteDay->timezone),
            SiteHosts::of($this->site)->hosts,
            (bool) config('analytics.require_rendering'),
            (int) config('analytics.visit_gap_minutes') * 60,
            (int) config('analytics.too_fast_pages'),
            (int) config('analytics.too_fast_seconds'),
            array_values($this->site->not_pages ?? []),
            $this->site->ownPathPatterns(),
        );

        $window = new DayWindow($this->day, $siteDay->timezone);
        $recounted = [...$recount->figures(), ...(new RevenueRecount($this->site->id, $window))->figures()];
        $differences = Comparison::differences($siteDay->core(), $recounted);

        $siteDay->update([
            'status' => $differences === [] ? DayStatus::Verified : DayStatus::Withheld,
            'problems' => $differences === [] ? null : $differences,
            'audited_at' => now(),
        ]);
    }
}
