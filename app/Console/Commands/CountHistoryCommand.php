<?php

namespace App\Console\Commands;

use App\Ingest\HistoryLog;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('edgelog:count-history')]
#[Description('Queue the count and the audit of the days past logs were added to, once their sending has settled')]
class CountHistoryCommand extends Command
{
    /**
     * Once no part of a past log came for ingest.history_settle_minutes, the
     * site's days those parts touched are counted again, then audited, in
     * one chain from the oldest: a day's returning visitors are told by the
     * days before it.
     */
    public function handle(): int
    {
        $settle = (int) config('ingest.history_settle_minutes') * 60;
        $queued = 0;

        Site::query()->each(function (Site $site) use ($settle, &$queued) {
            $days = $this->siteDays($site, HistoryLog::for($site)->takeSettledDays($settle));

            if ($days === []) {
                return;
            }

            Bus::chain(array_merge(...array_map(
                fn (string $day) => [new CollectSiteDay($site, $day), new AuditSiteDay($site, $day)],
                $days,
            )))->dispatch();

            $queued += count($days);
        });

        $this->components->info("Queued {$queued} site days.");

        return self::SUCCESS;
    }

    /**
     * The site's own days the UTC days overlap, oldest first, today left out.
     *
     * @param  list<string>  $utcDays
     * @return list<string>
     */
    private function siteDays(Site $site, array $utcDays): array
    {
        $today = now($site->timezone)->toDateString();
        $days = [];

        foreach ($utcDays as $utcDay) {
            $start = CarbonImmutable::parse($utcDay, 'UTC');

            foreach ([$start, $start->addDay()->subSecond()] as $instant) {
                $day = $instant->setTimezone($site->timezone)->toDateString();

                if ($day < $today) {
                    $days[$day] = true;
                }
            }
        }

        ksort($days);

        return array_keys($days);
    }
}
