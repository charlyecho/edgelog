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
    case RevenuePaid = 'revenue_paid';
    case RevenueRefunded = 'revenue_refunded';
    case Payments = 'payments';
    case VisitPages = 'visit_pages';
    case VisitSeconds = 'visit_seconds';
    case VisitExit = 'visit_exit';
    case VisitStep = 'visit_step';
    case VisitFlag = 'visit_flag';
    case VisitorReturning = 'visitor_returning';
    case ReturningSource = 'returning_source';

    /**
     * The breakdowns of money, by currency (never added across currencies).
     */
    public const array REVENUE = ['revenue_paid', 'revenue_refunded', 'payments'];

    /**
     * The histograms a median is read from: every row kept, never folded.
     */
    public const array UNFOLDED = ['visit_pages', 'visit_seconds'];

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
            self::RevenuePaid => __('Revenue'),
            self::RevenueRefunded => __('Refunds'),
            self::Payments => __('Payments'),
            self::VisitPages => __('Visits by pages read'),
            self::VisitSeconds => __('Visits by length'),
            self::VisitExit => __('Where visits ended'),
            self::VisitStep => __('Common next steps'),
            self::VisitFlag => __('Visits that met a page not found or sent a form'),
            self::VisitorReturning => __('New and returning visitors'),
            self::ReturningSource => __('Returning visitors by source'),
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
            self::PeopleCountry, self::PeopleDevice, self::PeopleBrowser, self::CitedBy,
            self::VisitPages, self::VisitSeconds, self::VisitExit, self::VisitFlag,
            self::VisitorReturning, self::ReturningSource => __('visitors'),
            self::VisitStep => __('moves'),
            self::RevenuePaid, self::RevenueRefunded => __('in the currency\'s minor unit'),
            self::Payments => __('payments'),
            default => __('requests'),
        };
    }
}
