<?php

namespace App\Jobs;

use App\Analytics\Stack\EndOfLife;
use App\Analytics\Stack\KnownFlaws;
use App\Analytics\Stack\ServerSoftware;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Judge what runs on a server: its system and each program against their
 * end of life (endoflife.date), and each program's known flaws (OSV.dev for
 * a build of the system's archive, the NVD for any other). Run when the
 * shipper says something changed, and once a day: new flaws come out.
 */
class CheckServerSoftware implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The NVD asks for a pause between requests without a key.
     */
    public int $timeout = 900;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(EndOfLife $endOfLife, KnownFlaws $knownFlaws): void
    {
        $software = $this->server->software;

        if ($software === null) {
            return;
        }

        $this->server->forceFill([
            'software' => ServerSoftware::judge($software, $endOfLife, $knownFlaws),
            'software_checked_at' => now(),
        ])->save();
    }
}
