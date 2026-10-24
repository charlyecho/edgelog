<?php

namespace App\Enums;

/**
 * Where a day of a site's figures stands.
 */
enum DayStatus: string
{
    // Counted, its sums check out, and the audit has not recounted it yet.
    case Collected = 'collected';

    // Counted twice, by two roads written apart, and both agree.
    case Verified = 'verified';

    // The figures do not add up, or the recount disagrees: never shown as figures.
    case Withheld = 'withheld';

    /**
     * Get the human readable name of the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Collected => __('Being checked'),
            self::Verified => __('Verified'),
            self::Withheld => __('Withheld'),
        };
    }
}
