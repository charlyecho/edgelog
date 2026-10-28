<?php

namespace App\Actions\Ingest;

use App\Ingest\IngestGate;
use App\Ingest\IngestRefusal;
use App\Ingest\ServerSample;
use App\Models\SiteMetricMinute;
use Illuminate\Http\Request;

/**
 * Take one minute of the site's server, posted by edgelog-ship --metrics.
 *
 * The checks of IngestGate first (the metrics have their own batches a
 * minute), then the sample must read (400). It is kept for the minute the
 * server got it, by this server's clock: a second sample in the same minute
 * takes the place of the first.
 */
class IngestServerSample
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, Request $request): SiteMetricMinute
    {
        $site = IngestGate::site($siteUuid, $request->bearerToken());

        IngestGate::throttle($site, 'metrics');

        $sample = ServerSample::fromJson(IngestGate::body($request)->lines);

        return $site->metricMinutes()->updateOrCreate(
            ['minute' => now()->startOfMinute()],
            $sample->toRow(),
        );
    }
}
