<?php

namespace App\Actions\Sites;

use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use Illuminate\Support\Facades\Bus;

/**
 * Keep scripts a site was found to serve its pages through, and count again
 * the days its log still holds: their requests to those scripts were taken
 * for probes.
 */
class RememberServedScripts
{
    /**
     * Keep the scripts the site did not have yet; the ones added.
     *
     * @param  list<string>  $scripts
     * @return list<string>
     */
    public function handle(Site $site, array $scripts): array
    {
        $known = $site->served_scripts ?? [];
        $added = array_values(array_diff($scripts, $known));

        if ($added === []) {
            return [];
        }

        $site->forceFill(['served_scripts' => [...$known, ...$added]])->save();

        $yesterday = now($site->timezone)->subDay()->toImmutable();
        $jobs = [];

        for ($n = (int) config('ingest.keep') - 1; $n >= 0; $n--) {
            $day = $yesterday->subDays($n)->toDateString();
            array_push($jobs, new CollectSiteDay($site, $day), new AuditSiteDay($site, $day));
        }

        Bus::chain($jobs)->dispatch();

        return $added;
    }
}
