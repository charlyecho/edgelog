<?php

namespace App\Analytics;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\Tally\DayTally;
use App\Ingest\IngestLog;
use App\Models\Site;

/**
 * Counts one day of a site from its shipped log, in two passes over the
 * window: the first learns what only a whole visit tells (VisitEvidence),
 * the second classifies every request in time order and adds it up.
 */
final class DayCollector
{
    /**
     * The day's figures, or null when the logs hold nothing for it (a day
     * before the site's first line is missing, never zero).
     */
    public function collect(Site $site, DayWindow $window): ?DayFigures
    {
        $hosts = SiteHosts::of($site);
        $reader = new LogWindowReader(IngestLog::for($site)->folder(), $site->log_format->parser(), $window);

        $evidence = new VisitEvidence(
            $hosts->hosts,
            (bool) config('analytics.require_rendering'),
            (int) config('analytics.visit_gap_minutes') * 60,
            (int) config('analytics.too_fast_pages'),
            (int) config('analytics.too_fast_seconds'),
        );

        $newest = PHP_INT_MIN;
        $lateness = 0;

        foreach ($reader->hits() as $hit) {
            $time = $hit->when->getTimestamp();
            $newest = max($newest, $time);
            $lateness = max($lateness, $newest - $time);

            if ($hosts->covers($hit)) {
                $evidence->add($hit);
            }
        }

        if ($reader->lines === 0 && ! $reader->reachesBack) {
            return null;
        }

        $evidence->finish();
        $classifier = new Classifier($evidence);
        $tally = new DayTally(new Sources($hosts->hosts), (int) config('analytics.visit_gap_minutes') * 60);

        foreach (TimeOrdered::of($reader->hits(), max(60, $lateness + 1)) as $hit) {
            $hosts->covers($hit) ? $tally->add($hit, $classifier->classify($hit)) : $tally->otherHost($hit->host);
        }

        $figures = $tally->finish();
        $figures->parsed = $reader->lines;
        $figures->unparsed = $reader->unparsed;
        $figures->partialFrom = $reader->reachesBack ? null : $figures->firstSeen;

        return $figures;
    }
}
