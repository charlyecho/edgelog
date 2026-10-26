<?php

namespace App\Actions\Ingest;

use App\Enums\IngestRefusalReason;
use App\Ingest\BatchId;
use App\Ingest\IngestGate;
use App\Ingest\IngestLog;
use App\Ingest\IngestLogFull;
use App\Ingest\IngestRefusal;
use App\Ingest\TeamDailyQuota;
use App\Ingest\UtcDay;
use App\Jobs\RefreshToday;
use App\Models\IngestStatus;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Take one batch of log lines a shipper posted for a site.
 *
 * In this order, before anything is kept: the checks of IngestGate, then a
 * batch id (400). Then the lines the site's log format cannot read are
 * counted and dropped, the team's bytes for the UTC day are counted (429),
 * and the accepted lines are appended to the site's log (429 when today's
 * file is full). A batch kept before is acknowledged
 * as a duplicate and not written again.
 *
 * Nothing about the sender is kept: no address, no user agent.
 */
class IngestBatch
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, Request $request): IngestOutcome
    {
        try {
            return $this->ingest($siteUuid, $request);
        } catch (IngestRefusal $refusal) {
            $site = Site::query()->where('uuid', $siteUuid)->first();

            if ($site !== null) {
                IngestStatus::recordRefusal($site, $refusal->reason);
            }

            throw $refusal;
        }
    }

    /**
     * @throws IngestRefusal
     */
    private function ingest(string $siteUuid, Request $request): IngestOutcome
    {
        $site = IngestGate::site($siteUuid, $request->bearerToken());

        IngestGate::throttle($site);
        IngestGate::ensureFreeDisk();

        $body = IngestGate::body($request);
        $batch = BatchId::tryFrom($request->header(BatchId::HEADER)) ?? throw new IngestRefusal(IngestRefusalReason::BadBatch);
        $log = IngestLog::for($site);

        if ($log->hasSeen($batch)) {
            return IngestOutcome::duplicate();
        }

        $lines = $body->accept($site->log_format->parser(), (int) config('ingest.max_line'));
        $quota = TeamDailyQuota::for($site->team);

        if (! $quota->reserve($lines->bytes())) {
            throw IngestRefusal::retryAfter(IngestRefusalReason::TeamLimit, UtcDay::retryAfter());
        }

        try {
            $stored = $log->append($batch, $lines->data);
        } catch (IngestLogFull) {
            $quota->release($lines->bytes());

            throw IngestRefusal::retryAfter(IngestRefusalReason::DayFull, UtcDay::retryAfter());
        }

        if (! $stored) {
            $quota->release($lines->bytes());

            return IngestOutcome::duplicate();
        }

        IngestStatus::recordBatch($site, $batch, $lines->accepted, $lines->rejected);

        if ($lines->accepted > 0) {
            RefreshToday::soon($site);
        }

        return new IngestOutcome($lines->accepted, $lines->rejected);
    }
}
