<?php

namespace App\Enums;

/**
 * The five groups every request lands in, exactly one each: together they
 * add up to the log.
 */
enum TrafficGroup: string
{
    case People = 'people';
    case AnswerEngines = 'answer_engines';
    case SearchEngines = 'search_engines';
    case Bots = 'bots';
    case Ours = 'ours';

    /**
     * Get the human readable name of the group.
     */
    public function label(): string
    {
        return match ($this) {
            self::People => __('People'),
            self::AnswerEngines => __('AI assistants'),
            self::SearchEngines => __('Search engines'),
            self::Bots => __('Bots and scanners'),
            self::Ours => __('Your own traffic'),
        };
    }

    /**
     * Get a short description of what the group holds.
     */
    public function description(): string
    {
        return match ($this) {
            self::People => __('A browser that is none of the groups below. Self-reported, so an upper bound.'),
            self::AnswerEngines => __('AI assistants\' crawlers and fetchers, by why they came.'),
            self::SearchEngines => __('Google, Bing and other classic indexing.'),
            self::Bots => __('Monitors, SEO tools, link previews, and probes hunting for secrets.'),
            self::Ours => __('Your team, your crawler, your tests.'),
        };
    }
}
