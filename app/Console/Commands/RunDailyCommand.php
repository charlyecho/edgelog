<?php

namespace App\Console\Commands;

use App\Analytics\DayWindow;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
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

            Bus::chain([new CollectSiteDay($site, $window->day), new AuditSiteDay($site, $window->day)])->dispatch();
            $queued++;
        });

        $this->components->info("Queued {$queued} site days.");

        return self::SUCCESS;
    }
}
