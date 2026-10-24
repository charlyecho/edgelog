<?php

namespace App\Console\Commands;

use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('edgelog:collect
    {site : The site\'s UUID}
    {--day= : The last day to count (YYYY-MM-DD), yesterday by default}
    {--days=1 : How many days to count, back from that day}
    {--sync : Count now, in this process, instead of queueing the jobs}')]
#[Description('Count and audit days of a site again, replacing what was kept for them')]
class CollectDaysCommand extends Command
{
    public function handle(): int
    {
        $site = Site::query()->where('uuid', $this->argument('site'))->first();

        if ($site === null) {
            $this->components->error('No site has this UUID.');

            return self::FAILURE;
        }

        $last = $this->option('day')
            ? CarbonImmutable::parse($this->option('day'), $site->timezone)
            : now($site->timezone)->subDay()->toImmutable();
        $days = max(1, (int) $this->option('days'));

        for ($n = $days - 1; $n >= 0; $n--) {
            $day = $last->subDays($n)->toDateString();
            $jobs = [new CollectSiteDay($site, $day), new AuditSiteDay($site, $day)];

            if ($this->option('sync')) {
                foreach ($jobs as $job) {
                    dispatch_sync($job);
                }

                $status = $site->days()->whereDate('day', $day)->first()?->status;
                $this->components->twoColumnDetail($day, $status?->label() ?? __('No line for this day'));
            } else {
                Bus::chain($jobs)->dispatch();
            }
        }

        if (! $this->option('sync')) {
            $this->components->info("Queued {$days} days of {$site->name}.");
        }

        return self::SUCCESS;
    }
}
