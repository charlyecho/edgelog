<?php

namespace App\Console\Commands;

use App\Jobs\RefreshLiveSnapshot;
use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edgelog:live')]
#[Description('Queue a refresh of today so far for every site that sent log lines in the last two hours')]
class RefreshLiveCommand extends Command
{
    public function handle(): int
    {
        $sites = Site::query()
            ->whereHas('ingestStatus', fn ($query) => $query->where('last_contact_at', '>=', now()->subHours(2)))
            ->get();

        $sites->each(fn (Site $site) => RefreshLiveSnapshot::dispatch($site));

        $this->components->info("Queued {$sites->count()} live refreshes.");

        return self::SUCCESS;
    }
}
