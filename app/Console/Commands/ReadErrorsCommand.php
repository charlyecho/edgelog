<?php

namespace App\Console\Commands;

use App\Actions\Ingest\IngestErrorBatch;
use App\Ingest\Errors\RequestMatcher;
use App\Models\Site;
use App\Models\SiteErrorOccurrence;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edgelog:errors {--prune : Forget the occurrences older than ingest.error_occurrence_days instead}')]
#[Description('Read the error log entries that waited long enough for the rest, and find the requests of the new occurrences')]
class ReadErrorsCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('prune')) {
            $pruned = SiteErrorOccurrence::query()->where('at', '<', now()->subDays((int) config('ingest.error_occurrence_days')))->delete();
            $this->components->info("Forgot {$pruned} error occurrences.");

            return self::SUCCESS;
        }

        $flushed = IngestErrorBatch::flushTails((int) config('ingest.error_tail_seconds'));
        $found = 0;

        Site::query()->whereHas('errorOccurrences', fn ($query) => $query->whereNull('looked_up_at'))
            ->each(function (Site $site) use (&$found) {
                $found += (new RequestMatcher($site))->run();
            });

        $this->components->info("Read {$flushed} held entries, found {$found} requests.");

        return self::SUCCESS;
    }
}
