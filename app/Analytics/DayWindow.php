<?php

namespace App\Analytics;

use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * One calendar day of a site, in the site's own time zone, as a window of
 * UTC instants: from its first second, up to the next day's first.
 */
final readonly class DayWindow
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(public string $day, public string $timezone)
    {
        $this->start = CarbonImmutable::parse($day, $timezone)->startOfDay()->utc();
        $this->end = CarbonImmutable::parse($day, $timezone)->addDay()->startOfDay()->utc();
    }

    /**
     * The given day of the site, in its time zone.
     */
    public static function for(Site $site, string $day): self
    {
        return new self($day, $site->timezone);
    }

    /**
     * The day before today, where the site is.
     */
    public static function yesterday(Site $site): self
    {
        return new self(now($site->timezone)->subDay()->toDateString(), $site->timezone);
    }

    /**
     * Determine if the instant falls in the window.
     */
    public function contains(CarbonImmutable $when): bool
    {
        return $when->greaterThanOrEqualTo($this->start) && $when->lessThan($this->end);
    }

    /**
     * Determine if the day is over everywhere a line of it could come from.
     */
    public function isOver(): bool
    {
        return now()->greaterThanOrEqualTo($this->end);
    }
}
