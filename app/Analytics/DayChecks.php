<?php

namespace App\Analytics;

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
            CountKind::OtherHost->value => $figures->otherHosts,
        ];

        foreach ($parents as $kind => $parent) {
            $sum = array_sum($figures->counts[$kind] ?? []);

            if ($sum !== $parent) {
                $problems[] = __('The breakdown :kind adds up to :sum, not :parent.', ['kind' => $kind, 'sum' => $sum, 'parent' => $parent]);
            }
        }

        return $problems;
    }
}
