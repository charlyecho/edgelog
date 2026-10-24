<?php

namespace App\Enums;

/**
 * Where a reader came from.
 */
enum SourceType: string
{
    case AiAssistant = 'AI assistant';
    case SearchEngine = 'Search engine';
    case SocialMedia = 'Social media';
    case Email = 'Email';
    case OtherWebsite = 'Other website';
    case ThisSite = 'This site';
    case TaggedLink = 'Tagged link';
    case Direct = 'Direct or unknown';

    /**
     * Get the human readable name of the source type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AiAssistant => __('AI assistant'),
            self::SearchEngine => __('Search engine'),
            self::SocialMedia => __('Social media'),
            self::Email => __('Email'),
            self::OtherWebsite => __('Other website'),
            self::ThisSite => __('This site'),
            self::TaggedLink => __('Tagged link'),
            self::Direct => __('Direct'),
        };
    }

    /**
     * Determine if a page from this source continues an open visit: a page
     * reached from the site itself, or with no referrer and no tag.
     */
    public function isInside(): bool
    {
        return $this === self::ThisSite || $this === self::Direct;
    }
}
