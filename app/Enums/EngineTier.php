<?php

namespace App\Enums;

/**
 * Why an AI assistant fetched a page. The tiers are not comparable
 * quantities, so they are never added together into one figure of interest.
 */
enum EngineTier: string
{
    case Training = 'training';
    case Asked = 'asked';

    /**
     * Get the human readable name of the tier.
     */
    public function label(): string
    {
        return match ($this) {
            self::Training => __('To train or index'),
            self::Asked => __('Because someone asked'),
        };
    }
}
