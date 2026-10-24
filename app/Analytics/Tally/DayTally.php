<?php

namespace App\Analytics\Tally;

use App\Analytics\Classification\Verdict;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\DayFigures;
use App\Analytics\PageRule;
use App\Analytics\Sources;
use App\Analytics\UserAgentLabels;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;

/**
 * Adds up a day, one classified request at a time, in time order.
 */
final class DayTally
{
    public readonly DayFigures $figures;

    /**
     * @var array<string, VisitCounter>
     */
    private array $visits = [];

    /**
     * People's visits that read a page, by visit number: where they came from.
     *
     * @var array<int, array{source: string, referrer: string, landing: string}>
     */
    private array $readerVisits = [];

    /**
     * People's visits a signed-in user made, by visit number.
     *
     * @var array<int, true>
     */
    private array $signedInVisits = [];

    /**
     * Each signed-in user's first visit of the day.
     *
     * @var array<string, int>
     */
    private array $firstVisitOf = [];

    public function __construct(private readonly Sources $sources, int $gapSeconds = 1800)
    {
        $this->figures = new DayFigures;

        foreach (TrafficGroup::cases() as $group) {
            $this->visits[$group->value] = new VisitCounter($gapSeconds);
        }
    }

    /**
     * A request for a host the site is not about: a sibling service, a bare address.
     */
    public function otherHost(string $host): void
    {
        $this->figures->otherHosts++;
        $this->figures->count(CountKind::OtherHost, substr($host, 0, 100));
    }

    public function add(LogHit $hit, Verdict $verdict): void
    {
        $figures = $this->figures;
        $group = $figures->group($verdict->group);
        $isPage = PageRule::isPage($hit);
        $path = substr($hit->path(), 0, 200) ?: '/';

        $figures->hits++;
        $figures->firstSeen ??= $hit->when;
        $figures->lastSeen = $hit->when;

        if ($hit->host === '') {
            $figures->unfiltered++;
        }

        $group->requests++;
        $group->views += $isPage ? 1 : 0;
        $group->probes += $verdict->probe ? 1 : 0;

        $key = VisitEvidence::key($hit);
        $time = $hit->when->getTimestamp();

        match ($verdict->group) {
            TrafficGroup::People => $this->person($hit, $verdict, $key, $time, $isPage, $path),
            TrafficGroup::AnswerEngines => $this->engine($verdict, $key, $time, $isPage, $path),
            TrafficGroup::SearchEngines => $this->search($verdict, $key, $time, $isPage, $path),
            TrafficGroup::Bots, TrafficGroup::Ours => $this->other($verdict, $key, $time, $isPage),
        };
    }

    /**
     * The visits and readers of every group, once the day was added.
     */
    public function finish(): DayFigures
    {
        foreach ($this->visits as $group => $counter) {
            $this->figures->groups[$group]->visits = $counter->visits;
            $this->figures->groups[$group]->readers = $counter->readers;
        }

        $this->figures->signedInReaders = count(array_intersect_key($this->signedInVisits, $this->readerVisits));

        foreach ($this->figures->users as $uid => &$user) {
            $origin = $this->readerVisits[$this->firstVisitOf[$uid]] ?? null;
            $user['source'] = $origin['source'] ?? null;
            $user['referrer'] = $origin['referrer'] ?? null;
            $user['landing'] = $origin['landing'] ?? null;
        }

        $this->figures->fold();

        return $this->figures;
    }

    private function person(LogHit $hit, Verdict $verdict, string $key, int $time, bool $isPage, string $path): void
    {
        $this->personVisit($hit, $verdict, $key, $time, $isPage, $path);

        if ($hit->userId === '') {
            return;
        }

        $visit = $this->visits[TrafficGroup::People->value]->visitOf($key);
        $this->signedInVisits[$visit] = true;
        $this->firstVisitOf[$hit->userId] ??= $visit;
        $this->figures->users[$hit->userId] ??= ['views' => 0, 'source' => null, 'referrer' => null, 'landing' => null, 'first_seen' => $hit->when];
        $this->figures->users[$hit->userId]['views'] += $isPage ? 1 : 0;
    }

    private function personVisit(LogHit $hit, Verdict $verdict, string $key, int $time, bool $isPage, string $path): void
    {
        if (! $isPage) {
            $this->visits[TrafficGroup::People->value]->add($key, $time);

            return;
        }

        $this->figures->count(CountKind::PeoplePage, $path);

        $cited = $verdict->citedBy !== '';
        $source = $this->sources->sourceType($hit->referrer, $hit->url, $cited);
        $referrer = $this->sources->referrerLabel($hit->referrer);
        $campaign = Sources::campaign($hit->url);

        $firstPage = $this->visits[TrafficGroup::People->value]->add(
            $key,
            $time,
            "{$source->value}\0{$referrer}\0{$campaign}",
            ! $source->isInside(),
        );

        if (! $firstPage) {
            return;
        }

        $this->readerVisits[$this->visits[TrafficGroup::People->value]->visitOf($key)] = [
            'source' => $source->value,
            'referrer' => $referrer,
            'landing' => $path,
        ];
        $this->figures->count(CountKind::PeopleLanding, $path);
        $this->figures->count(CountKind::PeopleSource, $source->value);
        $this->figures->count(CountKind::PeopleReferrer, $referrer);
        $this->figures->count(CountKind::PeopleCampaign, $campaign);
        $this->figures->count(CountKind::PeopleCountry, $hit->country !== '' ? $hit->country : 'Unknown');
        $this->figures->count(CountKind::PeopleDevice, UserAgentLabels::device($hit->userAgent));
        $this->figures->count(CountKind::PeopleBrowser, UserAgentLabels::browser($hit->userAgent));

        if ($cited) {
            $this->figures->citedVisits++;
            $this->figures->count(CountKind::CitedBy, $verdict->citedBy);
        }
    }

    private function engine(Verdict $verdict, string $key, int $time, bool $isPage, string $path): void
    {
        $this->visits[TrafficGroup::AnswerEngines->value]->add($key, $time, $isPage ? '' : null);
        $this->figures->count(CountKind::EngineTier, $verdict->tier->value);
        $this->figures->count(CountKind::EngineName, $verdict->name);
        $this->figures->count(CountKind::EnginePage, $path);
    }

    private function search(Verdict $verdict, string $key, int $time, bool $isPage, string $path): void
    {
        $this->visits[TrafficGroup::SearchEngines->value]->add($key, $time, $isPage ? '' : null);
        $this->figures->count(CountKind::SearchEngine, $verdict->name);
        $this->figures->count(CountKind::SearchPage, $path);
    }

    private function other(Verdict $verdict, string $key, int $time, bool $isPage): void
    {
        $this->visits[$verdict->group->value]->add($key, $time, $isPage ? '' : null);

        if ($verdict->group === TrafficGroup::Bots) {
            $this->figures->count(CountKind::BotAgent, $verdict->name);
        }
    }
}
