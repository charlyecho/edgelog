<?php

namespace App\Console\Commands;

use App\Analytics\Server\MetricRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edgelog:roll-metrics')]
#[Description('Sum the servers\' figures of the hours that are over up into hours, and forget the old ones')]
class RollMetricsCommand extends Command
{
    public function handle(): int
    {
        $written = MetricRollup::run(CarbonImmutable::now());

        $this->components->info("Summed up {$written} server hours.");

        return self::SUCCESS;
    }
}
