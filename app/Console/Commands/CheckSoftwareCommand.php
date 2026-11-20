<?php

namespace App\Console\Commands;

use App\Jobs\CheckServerSoftware;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edgelog:check-software')]
#[Description('Judge again what runs on each server against its end of life and its known flaws (CVE)')]
class CheckSoftwareCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Server::query()->whereNotNull('software')->each(function (Server $server) use (&$count) {
            CheckServerSoftware::dispatch($server);
            $count++;
        });

        $this->components->info("Queued the software check of {$count} servers.");

        return self::SUCCESS;
    }
}
