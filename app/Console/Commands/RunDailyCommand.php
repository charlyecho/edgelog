<?php

namespace App\Console\Commands;

use App\Analytics\DayWindow;
use App\Jobs\AuditSiteDay;
use App\Jobs\CheckStack;
use App\Jobs\CollectSiteDay;
use App\Jobs\FetchRobotsTxt;
use App\Jobs\FetchSitemap;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\SiteDayAttack;
use App\Models\SiteDaySuspect;
use App\Models\SiteDayUser;
use App\Models\SiteUser;
use App\Models\SiteVisit;
use App\Models\SiteVisitorDay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('edgelog:run-daily')]
#[Description('Queue the count and the audit of yesterday for every site whose day is over')]
class RunDailyCommand extends Command
{
    /**
     * Each site's yesterday, once it is over where the site is and the
     * shippers had time to send its last lines, is counted then audited,
     * once: a day already collected is left alone (edgelog:collect redoes one).
     */
    public function handle(): int
    {
        $settle = (int) config('analytics.settle_minutes');
        $queued = 0;

        Site::query()->each(function (Site $site) use ($settle, &$queued) {
            $window = DayWindow::yesterday($site);

            if (now()->lessThan($window->end->addMinutes($settle))) {
                return;
            }

            if ($site->days()->whereDate('day', $window->day)->exists()) {
                return;
            }

            Bus::chain([new FetchRobotsTxt($site), new FetchSitemap($site), new CheckStack($site)])->dispatch();

            Bus::chain([new CollectSiteDay($site, $window->day), new AuditSiteDay($site, $window->day)])->dispatch();
            $queued++;
        });

        $this->forgetOldUsers();
        $this->forgetOldVisits();
        $this->components->info("Queued {$queued} site days.");

        return self::SUCCESS;
    }

    /**
     * Signed-in users' ids are kept users_kept_days, no longer: the days'
     * lists of users, and the users not seen since. The day's counts stay.
     */
    private function forgetOldUsers(): void
    {
        $before = now()->subDays((int) config('analytics.users_kept_days'))->toDateString();

        SiteDayUser::query()->whereIn('site_day_id', SiteDay::query()->where('day', '<', $before)->select('id'))->delete();
        SiteUser::query()->where('last_seen_day', '<', $before)->delete();
    }

    /**
     * Visits are kept journey_days, visitors' hashes visitors_kept_days and
     * the addresses of scanners and attackers security.kept_days, no longer,
     * counted from today: the days' counts stay.
     */
    private function forgetOldVisits(): void
    {
        $olderThan = fn (string $setting) => SiteDay::query()
            ->where('day', '<=', now()->subDays((int) config("analytics.{$setting}"))->toDateString())
            ->select('id');

        SiteVisit::query()->whereIn('site_day_id', $olderThan('journey_days'))->delete();
        SiteVisitorDay::query()->whereIn('site_day_id', $olderThan('visitors_kept_days'))->delete();
        SiteDaySuspect::query()->whereIn('site_day_id', $olderThan('security.kept_days'))->delete();
        SiteDayAttack::query()->whereIn('site_day_id', $olderThan('security.kept_days'))->delete();
    }
}
