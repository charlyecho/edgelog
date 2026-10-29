<?php

namespace App\Actions\Ingest;

use App\Ingest\IngestGate;
use App\Ingest\IngestRefusal;
use App\Ingest\ServerSample;
use App\Models\Server;
use App\Models\ServerMetricMinute;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Take one minute of the site's server, posted by edgelog-ship --metrics.
 *
 * The checks of IngestGate first (the metrics have their own batches a
 * minute), then the sample must read (400). The site is tied to the server
 * of the team its machine id names, made at its first sample; a shipper too
 * old to send one keeps the site's server, or makes one of its own. A server
 * picked by hand for the site takes the sample, whatever machine it names.
 * The sample is kept for the minute the server got it, by this server's
 * clock: a second sample in the same minute, from this site's shipper or
 * from another site's on the same machine, takes the place of the first.
 */
class IngestServerSample
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, Request $request): ServerMetricMinute
    {
        $site = IngestGate::site($siteUuid, $request->bearerToken());

        IngestGate::throttle($site, 'metrics');

        $sample = ServerSample::fromJson(IngestGate::body($request)->lines);
        $server = $this->server($site, $sample);
        $minute = now()->startOfMinute();

        $server->forceFill(['last_sample_at' => $minute, 'hostname' => $sample->hostname ?? $server->hostname])->save();

        return $server->metricMinutes()->updateOrCreate(['minute' => $minute], $sample->toRow());
    }

    /**
     * The server the sample comes from, the site tied to it.
     */
    private function server(Site $site, ServerSample $sample): Server
    {
        if ($site->server_pinned && $site->server !== null) {
            return $site->server;
        }

        $server = $sample->machineId === null ? $site->server : $this->byMachine($site, $sample->machineId);

        $server ??= $sample->machineId === null
            ? $site->team->servers()->create(['hostname' => $sample->hostname])
            : $site->team->servers()->createOrFirst(['machine_id' => $sample->machineId], ['hostname' => $sample->hostname]);

        if ($site->server_id !== $server->id) {
            $site->server()->associate($server)->save();
        }

        return $server;
    }

    /**
     * The team's server of that machine; else the site's server while its
     * machine is unknown (made before machine ids), which takes it.
     */
    private function byMachine(Site $site, string $machineId): ?Server
    {
        $server = $site->team->servers()->where('machine_id', $machineId)->first();

        if ($server === null && $site->server !== null && $site->server->machine_id === null) {
            $server = $site->server;
            $server->forceFill(['machine_id' => $machineId])->save();
        }

        return $server;
    }
}
