<?php

namespace App\Actions\Ingest;

use App\Enums\IngestRefusalReason;
use App\Ingest\HistoryLog;
use App\Ingest\HistoryRange;
use App\Ingest\IngestGate;
use App\Ingest\IngestLog;
use App\Ingest\IngestRefusal;
use Illuminate\Http\Request;

/**
 * Take one part of a past log file `edgelog-ship --backfill` posted for a site.
 *
 * In this order, before anything is kept: the checks of IngestGate, with a
 * rate limit of their own so a backfill never slows the live log; a range
 * (400); something shipped live already (409: its first line tells which
 * past lines are new); a part that starts where the file was kept up to
 * (409, the answer says where). Then the lines the site's log format cannot
 * read are counted and dropped, those at or after the first live line are
 * counted and left to the live log, and the others are kept by UTC day.
 *
 * Past lines do not count against the team's daily bytes: they are not
 * today's traffic.
 */
class IngestHistoryBatch
{
    /**
     * @throws IngestRefusal
     */
    public function handle(string $siteUuid, Request $request): HistoryOutcome
    {
        $site = IngestGate::site($siteUuid, $request->bearerToken());

        IngestGate::throttle($site, 'ingest-history');
        IngestGate::ensureFreeDisk();

        $body = IngestGate::body($request);
        $range = HistoryRange::tryFrom($request->header(HistoryRange::HEADER)) ?? throw new IngestRefusal(IngestRefusalReason::BadRange);
        $parser = $site->log_format->parser();
        $firstLive = IngestLog::for($site)->firstLineTime($parser) ?? throw new IngestRefusal(IngestRefusalReason::NoLiveLog);
        $history = HistoryLog::for($site);

        if ($history->offset($range->source) !== $range->start) {
            throw IngestRefusal::offset($history->offset($range->source));
        }

        $lines = $body->accept($parser, (int) config('ingest.max_line'));
        $linesByDay = [];
        $kept = $shipped = 0;

        foreach (explode("\n", rtrim($lines->data, "\n")) as $line) {
            $timestamp = $line === '' ? null : $parser->timestamp($line);

            if ($timestamp === null) {
                continue;
            }

            if ($timestamp >= $firstLive) {
                $shipped++;

                continue;
            }

            $day = gmdate('Y-m-d', $timestamp);
            $linesByDay[$day] = ($linesByDay[$day] ?? '').$line."\n";
            $kept++;
        }

        if (! $history->append($range, $linesByDay, $kept)) {
            throw IngestRefusal::offset($history->offset($range->source));
        }

        return new HistoryOutcome($kept, $lines->rejected, $shipped, $range->end);
    }
}
