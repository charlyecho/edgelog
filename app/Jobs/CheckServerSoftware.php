<?php

namespace App\Jobs;

use App\Analytics\Stack\EndOfLife;
use App\Analytics\Stack\KnownFlaws;
use App\Analytics\Stack\PortProbe;
use App\Analytics\Stack\ServerSoftware;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Judge what runs on a server: its system and each program against their
 * end of life (endoflife.date), and each program's known flaws (OSV.dev for
 * a build of the system's archive, the NVD for any other); and whether the
 * private services it opens on every interface answer from outside. Run
 * when the shipper says something changed, and once a day: new flaws come
 * out, firewalls change.
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

    public function handle(EndOfLife $endOfLife, KnownFlaws $knownFlaws, PortProbe $probe): void
    {
        $software = $this->server->software;

        if ($software === null) {
            return;
        }

        $software = ServerSoftware::judge($software, $endOfLife, $knownFlaws);
        $software['exposure'] = ServerSoftware::reach($software['system'] ?? null, $this->server->public_address, $probe);

        // Read again: a sample may have come in while the flaws were read.
        $this->server->refresh()->forceFill([
            'software' => [...ServerSoftware::withJudgements($software, $this->server->software ?? $software), 'exposure' => $software['exposure']],
            'software_checked_at' => now(),
        ])->save();
    }
}
