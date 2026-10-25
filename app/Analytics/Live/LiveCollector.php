<?php

namespace App\Analytics\Live;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\DayWindow;
use App\Analytics\Geo\Places;
use App\Analytics\LogWindowReader;
use App\Analytics\NotPages;
use App\Analytics\OwnPaths;
use App\Analytics\PageRule;
use App\Analytics\SiteHosts;
use App\Analytics\Sources;
use App\Analytics\TimeOrdered;
use App\Analytics\UserAgentLabels;
use App\Enums\SourceType;
use App\Enums\TrafficGroup;
use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Models\Site;

/**
 * Today so far, for the Real time tab: who is on the site now, what they
 * read, where they came from, and which machines are fetching.
 *
 * It reads today's lines with the same rules as the daily count, so its
 * "visitors today" is the count the day will have so far. It never writes
 * the history: the day is counted for good once it is over.
 */
final class LiveCollector
{
    public const int ACTIVE_SECONDS = 300;

    public const int RECENT_SECONDS = 1800;

    public const int MINUTES = 30;

    public function __construct(private readonly Places $places) {}

    public function collect(Site $site): LiveSnapshot
    {
        $now = now()->toImmutable();
        $window = DayWindow::todaySoFar($site);
        $hosts = SiteHosts::of($site);
        $reader = new LogWindowReader(IngestLog::for($site)->folder(), $site->log_format->parser(), $window);
        $gap = (int) config('analytics.visit_gap_minutes') * 60;
        $notPages = NotPages::of($site);
        $ownPaths = OwnPaths::of($site);

        $evidence = new VisitEvidence($hosts->hosts, (bool) config('analytics.require_rendering'), $gap,
            (int) config('analytics.too_fast_pages'), (int) config('analytics.too_fast_seconds'), $notPages, $ownPaths);

        foreach ($reader->hits() as $hit) {
            if ($hosts->covers($hit)) {
                $evidence->add($hit);
            }
        }

        $evidence->finish();
        $classifier = new Classifier($evidence, $ownPaths);
        $sources = new Sources($hosts->hosts);

        $nowTime = $now->getTimestamp();
        $firstMinute = intdiv($nowTime, 60) * 60 - (self::MINUTES - 1) * 60;
        $requestsByMinute = array_fill(0, self::MINUTES, 0);
        $snapshot = new LiveSnapshot($now, $site->timezone);

        /** @var array<string, array<string, mixed>> $open */
        $open = [];
        $visits = [];

        foreach (TimeOrdered::of($reader->hits(), 120) as $hit) {
            if (! $hosts->covers($hit)) {
                continue;
            }

            $time = $hit->when->getTimestamp();
            $recent = $time >= $nowTime - self::RECENT_SECONDS;
            $verdict = $classifier->classify($hit);

            if ($time >= $firstMinute) {
                $requestsByMinute[intdiv($time - $firstMinute, 60)]++;
            }

            if ($recent) {
                match ($verdict->group) {
                    TrafficGroup::AnswerEngines => $snapshot->count('engines', $verdict->name),
                    TrafficGroup::SearchEngines => $snapshot->count('search', $verdict->name),
                    TrafficGroup::Bots => $snapshot->bots++,
                    default => null,
                };
            }

            if ($verdict->group !== TrafficGroup::People) {
                continue;
            }

            $this->person($hit, $verdict->citedBy, $sources, $gap, $notPages, $open, $visits);

            if ($recent && PageRule::isPage($hit, $notPages)) {
                $snapshot->pagesRecent++;
                $snapshot->count('pages', substr($hit->path(), 0, 200) ?: '/');
            }
        }

        $signedIn = [];
        $signedInRecent = [];

        foreach ([...$visits, ...array_values($open)] as $visit) {
            foreach ($visit['users'] as $uid => $true) {
                $signedIn[$uid] = true;

                if ($visit['last'] >= $nowTime - self::RECENT_SECONDS) {
                    $signedInRecent[$uid] = true;
                }
            }

            if ($visit['pages'] === 0) {
                continue;
            }

            $snapshot->visitorsToday++;
            $active = $visit['last'] >= $nowTime - self::ACTIVE_SECONDS;
            $recent = $visit['last'] >= $nowTime - self::RECENT_SECONDS;
            $snapshot->activeNow += $active ? 1 : 0;
            $snapshot->countCountry($visit['country'], $recent);

            if ($recent) {
                $snapshot->recentVisitors++;
                $snapshot->count('sources', $visit['source']);
                $snapshot->count('devices', $visit['device']);
            }

            $lastMinute = intdiv($visit['last'], 60) * 60;

            for ($minute = max(intdiv($visit['start'], 60) * 60, $firstMinute); $minute <= $lastMinute; $minute += 60) {
                $index = intdiv($minute - $firstMinute, 60);
                $snapshot->visitorsByMinute[$index] = ($snapshot->visitorsByMinute[$index] ?? 0) + 1;
            }

            $snapshot->newest[] = [
                'started' => $visit['start'],
                'country' => $visit['country'],
                'device' => $visit['device'],
                'from' => $visit['from'],
                'pages' => $visit['pages'],
                'landing' => $visit['landing'],
                'active' => $active ? 'now' : ($recent ? 'recent' : null),
                'signed_in' => $visit['users'] !== [],
            ];
        }

        $snapshot->signedInToday = count($signedIn);
        $snapshot->signedInRecent = count($signedInRecent);

        $snapshot->firstMinute = $firstMinute;
        $snapshot->requestsByMinute = $requestsByMinute;
        $snapshot->visitorsByMinute = array_replace(array_fill(0, self::MINUTES, 0), $snapshot->visitorsByMinute);
        $snapshot->finish();

        return $snapshot;
    }

    /**
     * Add a person's request to their visit: one address and browser, no gap
     * over 30 minutes; a page from a new outside source starts a new visit.
     *
     * @param  array<string, array<string, mixed>>  $open
     * @param  list<array<string, mixed>>  $visits
     */
    private function person(LogHit $hit, string $citedBy, Sources $sources, int $gap, NotPages $notPages, array &$open, array &$visits): void
    {
        $key = VisitEvidence::key($hit);
        $time = $hit->when->getTimestamp();
        $visit = $open[$key] ?? null;
        $isPage = PageRule::isPage($hit, $notPages);
        $origin = null;
        $source = null;

        if ($isPage) {
            $source = $sources->sourceType($hit->referrer, $hit->url, $citedBy !== '');
            $origin = $source->value."\0".$sources->referrerLabel($hit->referrer)."\0".Sources::campaign($hit->url);
        }

        $splits = $visit !== null && $origin !== null && ! $source->isInside() && $visit['origin'] !== null && $visit['origin'] !== $origin;

        if ($visit === null || $time - $visit['last'] > $gap || $splits) {
            if ($visit !== null) {
                $visits[] = $visit;
            }

            $visit = ['start' => $time, 'last' => $time, 'pages' => 0, 'origin' => null, 'landing' => null,
                'source' => null, 'from' => null, 'country' => $this->places->of($hit->address, $hit->country)->country ?: 'Unknown',
                'device' => UserAgentLabels::device($hit->userAgent), 'users' => []];
        }

        $visit['last'] = max($visit['last'], $time);

        if ($hit->userId !== '') {
            $visit['users'][$hit->userId] = true;
        }

        if ($isPage) {
            $visit['pages']++;

            if ($visit['origin'] === null) {
                $visit['origin'] = $origin;
                $visit['landing'] = substr($hit->path(), 0, 200) ?: '/';
                $visit['source'] = $source->value;
                $visit['from'] = match ($source) {
                    SourceType::AiAssistant => $citedBy,
                    SourceType::SearchEngine, SourceType::SocialMedia, SourceType::Email, SourceType::OtherWebsite => $sources->referrerLabel($hit->referrer),
                    SourceType::TaggedLink => Sources::campaign($hit->url),
                    default => null,
                };
            }
        }

        $open[$key] = $visit;
    }
}
