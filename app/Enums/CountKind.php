<?php

namespace App\Enums;

/**
 * A breakdown kept per day. Each adds up to the figure it breaks down
 * (a long list is folded into "(all others)", never cut).
 */
enum CountKind: string
{
    case PeoplePage = 'people_page';
    case PeopleLanding = 'people_landing';
    case PeopleSource = 'people_source';
    case PeopleReferrer = 'people_referrer';
    case PeopleCampaign = 'people_campaign';
    case PeopleCountry = 'people_country';
    case PeopleDevice = 'people_device';
    case PeopleBrowser = 'people_browser';
    case CitedBy = 'cited_by';
    case EngineTier = 'engine_tier';
    case EngineName = 'engine_name';
    case EnginePage = 'engine_page';
    case SearchEngine = 'search_engine';
    case SearchPage = 'search_page';
    case BotAgent = 'bot_agent';
    case OtherHost = 'other_host';

    /**
     * The name a folded tail is kept under.
     */
    public const string OTHERS = '(all others)';

    /**
     * Get the human readable name of the breakdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::PeoplePage => __('Top pages'),
            self::PeopleLanding => __('Landing pages'),
            self::PeopleSource => __('Sources'),
            self::PeopleReferrer => __('Referrers'),
            self::PeopleCampaign => __('Campaigns'),
            self::PeopleCountry => __('Countries'),
            self::PeopleDevice => __('Devices'),
            self::PeopleBrowser => __('Browsers'),
            self::CitedBy => __('Visitors from AI assistants'),
            self::EngineTier => __('AI assistants, by why they came'),
            self::EngineName => __('AI assistants'),
            self::EnginePage => __('Pages AI assistants fetched'),
            self::SearchEngine => __('Search engines'),
            self::SearchPage => __('Pages search engines fetched'),
            self::BotAgent => __('Bots and scanners'),
            self::OtherHost => __('Other hosts, left out'),
        };
    }

    /**
     * Get what the breakdown counts, to show beside its numbers.
     */
    public function unit(): string
    {
        return match ($this) {
            self::PeoplePage => __('pages read'),
            self::PeopleLanding, self::PeopleSource, self::PeopleReferrer, self::PeopleCampaign,
            self::PeopleCountry, self::PeopleDevice, self::PeopleBrowser, self::CitedBy => __('visitors'),
            default => __('requests'),
        };
    }
}
