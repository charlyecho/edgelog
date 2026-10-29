<?php

namespace App\Analytics;

use App\Analytics\Health\HealthCollector;
use App\Analytics\Tally\VisitJourneys;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;

/**
 * The arithmetic every figure depends on, checked before a day is kept as
 * figures. A day that does not add up is withheld, never shown.
 */
final class DayChecks
{
    /**
     * The most lines of a day that may be unreadable.
     */
    public const float MAX_UNPARSED_SHARE = 0.01;

    /**
     * @return list<string>
     */
    public static function problems(DayFigures $figures): array
    {
        $problems = [];
        $requests = array_sum(array_map(fn (GroupFigures $group) => $group->requests, $figures->groups));

        if ($requests !== $figures->hits) {
            $problems[] = __('The groups add up to :sum requests, not :hits.', ['sum' => $requests, 'hits' => $figures->hits]);
        }

        if ($figures->hits + $figures->otherHosts + $figures->unparsed !== $figures->parsed) {
            $problems[] = __('The lines read do not add up to the requests, other hosts and unreadable lines.');
        }

        if ($figures->parsed > 0 && $figures->unparsed / $figures->parsed > self::MAX_UNPARSED_SHARE) {
            $problems[] = __(':count of :lines lines could not be read in the site\'s log format.', ['count' => $figures->unparsed, 'lines' => $figures->parsed]);
        }

        foreach ($figures->groups as $name => $group) {
            foreach (['views' => $group->views, 'visits' => $group->visits, 'probes' => $group->probes] as $figure => $value) {
                if ($value > $group->requests) {
                    $problems[] = __(':group: more :figure than requests.', ['group' => $name, 'figure' => $figure]);
                }
            }

            if ($group->readers > min($group->visits, $group->views)) {
                $problems[] = __(':group: more readers than visits or page views.', ['group' => $name]);
            }
        }

        $people = $figures->group(TrafficGroup::People);

        if ($figures->signedInReaders > $people->readers) {
            $problems[] = __('More signed-in visitors than visitors.');
        }
        $parents = [
            CountKind::PeoplePage->value => $people->views,
            CountKind::PeopleLanding->value => $people->readers,
            CountKind::PeopleSource->value => $people->readers,
            CountKind::PeopleReferrer->value => $people->readers,
            CountKind::PeopleCampaign->value => $people->readers,
            CountKind::PeopleCountry->value => $people->readers,
            CountKind::PeopleDevice->value => $people->readers,
            CountKind::PeopleBrowser->value => $people->readers,
            CountKind::CitedBy->value => $figures->citedVisits,
            CountKind::EngineTier->value => $figures->group(TrafficGroup::AnswerEngines)->requests,
            CountKind::EngineName->value => $figures->group(TrafficGroup::AnswerEngines)->requests,
            CountKind::EnginePage->value => $figures->group(TrafficGroup::AnswerEngines)->requests,
            CountKind::SearchEngine->value => $figures->group(TrafficGroup::SearchEngines)->requests,
            CountKind::SearchPage->value => $figures->group(TrafficGroup::SearchEngines)->requests,
            CountKind::BotAgent->value => $figures->group(TrafficGroup::Bots)->requests,
            CountKind::BotPath->value => $figures->group(TrafficGroup::Bots)->requests,
            CountKind::OtherHost->value => $figures->otherHosts,
        ];

        $parents += [
            CountKind::VisitPages->value => $people->readers,
            CountKind::VisitSeconds->value => $people->readers,
            CountKind::VisitExit->value => $people->readers,
            CountKind::VisitorReturning->value => $people->readers,
            CountKind::ReturningSource->value => $figures->countsOf(CountKind::VisitorReturning)['returning'] ?? 0,
            CountKind::PeopleSystem->value => $people->readers,
            CountKind::PeopleCity->value => $people->readers,
            CountKind::VisitHour->value => $people->readers,
            CountKind::PeopleSocial->value => $figures->countsOf(CountKind::PeopleSource)['Social media'] ?? 0,
            CountKind::EngineAskedPage->value => $figures->countsOf(CountKind::EngineTier)['asked'] ?? 0,
            CountKind::EngineTrainingPage->value => $figures->countsOf(CountKind::EngineTier)['training'] ?? 0,
        ];

        foreach ($parents as $kind => $parent) {
            $sum = array_sum($figures->counts[$kind] ?? []);

            if ($sum !== $parent) {
                $problems[] = __('The breakdown :kind adds up to :sum, not :parent.', ['kind' => $kind, 'sum' => $sum, 'parent' => $parent]);
            }
        }

        return [...$problems, ...self::visitProblems($figures), ...self::securityProblems($figures), ...self::crawlerProblems($figures), ...self::healthProblems($figures)];
    }

    /**
     * The answers by status add up to the requests, people's page views by
     * answer time to their page views, and the errors by page to their status.
     *
     * @return list<string>
     */
    private static function healthProblems(DayFigures $figures): array
    {
        $statuses = $figures->countsOf(CountKind::HealthStatus);
        $people = TrafficGroup::People->value;
        $serverErrors = array_sum(array_filter($statuses, fn (string $name) => str_ends_with($name, ':5xx'), ARRAY_FILTER_USE_KEY));
        $speeds = $figures->countsOf(CountKind::HealthSpeed);
        $problems = [];

        foreach ([
            CountKind::HealthStatus->value => $figures->hits,
            CountKind::HealthSpeed->value => $figures->group(TrafficGroup::People)->views,
            CountKind::HealthServerError->value => $serverErrors,
            CountKind::HealthNotFound->value => $statuses["{$people}:404"] ?? 0,
            CountKind::HealthNotFoundFrom->value => $statuses["{$people}:404"] ?? 0,
            CountKind::HealthPageTimed->value => array_sum($speeds) - ($speeds[HealthCollector::NOT_LOGGED] ?? 0),
            CountKind::HealthSlowPage->value => array_sum(array_intersect_key($speeds, array_flip(HealthCollector::SLOW))),
        ] as $kind => $parent) {
            $sum = array_sum($figures->counts[$kind] ?? []);

            if ($sum !== $parent) {
                $problems[] = __('The breakdown :kind adds up to :sum, not :parent.', ['kind' => $kind, 'sum' => $sum, 'parent' => $parent]);
            }
        }

        return $problems;
    }

    /**
     * Each group's crawlers add up to its requests, and the assistants'
     * fetches by kind and by assistant to theirs.
     *
     * @return list<string>
     */
    private static function crawlerProblems(DayFigures $figures): array
    {
        $problems = [];
        $byGroup = [];

        foreach ($figures->crawlers as $crawler) {
            $byGroup[$crawler['group']] = ($byGroup[$crawler['group']] ?? 0) + $crawler['requests'];
        }

        foreach ($figures->groups as $group => $row) {
            if ($group !== TrafficGroup::People->value && ($byGroup[$group] ?? 0) !== $row->requests) {
                $problems[] = __('The crawlers of :group add up to :sum requests, not :requests.', ['group' => $group, 'sum' => $byGroup[$group] ?? 0, 'requests' => $row->requests]);
            }
        }

        $engines = $figures->group(TrafficGroup::AnswerEngines)->requests;

        foreach ([CountKind::CrawlerFetch->value => $engines, CountKind::AiAskedFetch->value => $figures->countsOf(CountKind::EngineTier)['asked'] ?? 0] as $kind => $parent) {
            if (array_sum($figures->counts[$kind] ?? []) !== $parent) {
                $problems[] = __('The breakdown :kind adds up to :sum, not :parent.', ['kind' => $kind, 'sum' => array_sum($figures->counts[$kind] ?? []), 'parent' => $parent]);
            }
        }

        return $problems;
    }

    /**
     * The probes add up by category and by answer, the injection attempts
     * by kind, and the fake browsers by what gave them away.
     *
     * @return list<string>
     */
    private static function securityProblems(DayFigures $figures): array
    {
        $security = $figures->security['figures'] ?? null;

        if ($security === null) {
            return [];
        }

        $problems = [];

        foreach ([
            CountKind::SecurityCategory->value => $security['probes'],
            CountKind::SecurityAnswer->value => $security['probes'],
            CountKind::SecurityPathAnswer->value => $security['probes'],
            CountKind::SecurityInjection->value => $security['injection'],
            CountKind::SecurityFake->value => $security['fake_requests'],
        ] as $kind => $parent) {
            $sum = array_sum($figures->counts[$kind] ?? []);

            if ($sum !== $parent) {
                $problems[] = __('The breakdown :kind adds up to :sum, not :parent.', ['kind' => $kind, 'sum' => $sum, 'parent' => $parent]);
            }
        }

        if ($security['answered_page'] !== $security['shell'] + $security['empty'] + $security['possible'] + $security['unknown']) {
            $problems[] = __('The probes answered with a page do not add up to their verdicts.');
        }

        return $problems;
    }

    /**
     * The visits add up to the readers, their pages to the pages read, and
     * the engagement rows to both, by source and by device alike.
     *
     * @return list<string>
     */
    private static function visitProblems(DayFigures $figures): array
    {
        $people = $figures->group(TrafficGroup::People);
        $problems = [];

        if (count($figures->journeys) !== $people->readers) {
            $problems[] = __('There are :count visits, not :readers.', ['count' => count($figures->journeys), 'readers' => $people->readers]);
        }

        $pages = 0;

        foreach ($figures->countsOf(CountKind::VisitPages) as $read => $visits) {
            $pages += (int) $read * $visits;
        }

        if ($pages !== $people->views) {
            $problems[] = __('The visits read :pages pages, not :views.', ['pages' => $pages, 'views' => $people->views]);
        }

        if (array_sum($figures->countsOf(CountKind::VisitStep)) > $people->views - $people->readers) {
            $problems[] = __('More steps between pages than pages after the first.');
        }

        foreach ($figures->engagement as $dimension => $rows) {
            $visits = array_sum(array_column($rows, 'visits'));
            $views = array_sum(array_column($rows, 'views'));
            $whole = in_array($dimension, VisitJourneys::WHOLE_DIMENSIONS, true);

            if ($whole ? $visits !== $people->readers || $views !== $people->views : $visits > $people->readers || $views > $people->views) {
                $problems[] = __('The engagement by :dimension does not add up to the visits and pages read.', ['dimension' => $dimension]);
            }

            foreach ($rows as $row) {
                if (array_sum($row['seconds']) !== $row['visits'] || $row['one_page'] > $row['visits'] || $row['visits'] > $row['views']
                    || $row['not_found'] > $row['visits'] || $row['posted'] > $row['visits']) {
                    $problems[] = __('The engagement by :dimension does not add up to the visits and pages read.', ['dimension' => $dimension]);

                    break;
                }
            }
        }

        return $problems;
    }
}
