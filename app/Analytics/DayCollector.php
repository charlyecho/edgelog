<?php

namespace App\Analytics;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\Geo\Places;
use App\Analytics\Tally\DayTally;
use App\Enums\ConversionAction;
use App\Enums\CountKind;
use App\Ingest\IngestLog;
use App\Models\Conversion;
use App\Models\Site;

/**
 * Counts one day of a site from its shipped log, in two passes over the
 * window: the first learns what only a whole visit tells (VisitEvidence),
 * the second classifies every request in time order and adds it up.
 */
final class DayCollector
{
    public function __construct(private readonly Places $places) {}

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
        $stylesheets = 0;

        foreach ($reader->hits() as $hit) {
            $time = $hit->when->getTimestamp();
            $newest = max($newest, $time);
            $lateness = max($lateness, $newest - $time);

            if ($hosts->covers($hit)) {
                $evidence->add($hit);
                $stylesheets += str_ends_with(strtolower($hit->path()), '.css') ? 1 : 0;
            }
        }

        if ($reader->lines === 0 && ! $reader->reachesBack) {
            return null;
        }

        $evidence->finish();
        $classifier = new Classifier($evidence);
        $tally = new DayTally(new Sources($hosts->hosts), (int) config('analytics.visit_gap_minutes') * 60, $this->places);

        foreach (TimeOrdered::of($reader->hits(), max(60, $lateness + 1)) as $hit) {
            $hosts->covers($hit) ? $tally->add($hit, $classifier->classify($hit)) : $tally->otherHost($hit->host);
        }

        $figures = $tally->finish();
        ReturningVisitors::mark($site, $window->day, $figures);
        $this->addRevenue($site, $window, $figures);
        $figures->parsed = $reader->lines;
        $figures->unparsed = $reader->unparsed;
        $figures->stylesheets = $stylesheets;
        $figures->partialFrom = $reader->reachesBack ? null : $figures->firstSeen;

        return $figures;
    }

    /**
     * The money the site's tools reported for the day, live payments only,
     * by currency: paid, refunded (as a positive amount) and how many payments.
     */
    private function addRevenue(Site $site, DayWindow $window, DayFigures $figures): void
    {
        $site->conversions()
            ->where('livemode', true)
            ->where('occurred_at', '>=', $window->start)
            ->where('occurred_at', '<', $window->end)
            ->get()
            ->each(function (Conversion $conversion) use ($figures) {
                if ($conversion->action === ConversionAction::Paid) {
                    $figures->count(CountKind::RevenuePaid, $conversion->currency, $conversion->amount);
                    $figures->count(CountKind::Payments, $conversion->currency);
                } else {
                    $figures->count(CountKind::RevenueRefunded, $conversion->currency, abs($conversion->amount));
                }
            });
    }
}
