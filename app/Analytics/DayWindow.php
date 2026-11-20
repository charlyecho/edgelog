<?php

namespace App\Analytics;

use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * One calendar day of a site, in the site's own time zone, as a window of
 * UTC instants: from its first second, up to the next day's first. A window
 * may start later than the day (the last hours only) or stop earlier (a day
 * not over yet).
 */
final readonly class DayWindow
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    /**
     * @param  ?CarbonImmutable  $until  where the window stops when the day is not over yet
     * @param  ?CarbonImmutable  $from  where the window starts when it is only the end of the day
     */
    public function __construct(public string $day, public string $timezone, ?CarbonImmutable $until = null, ?CarbonImmutable $from = null)
    {
        $this->start = $from?->utc() ?? CarbonImmutable::parse($day, $timezone)->startOfDay()->utc();
        $this->end = $until?->utc() ?? CarbonImmutable::parse($day, $timezone)->addDay()->startOfDay()->utc();
    }

    /**
     * Today where the site is, from its first second up to now.
     */
    public static function todaySoFar(Site $site): self
    {
        return new self(now($site->timezone)->toDateString(), $site->timezone, now()->toImmutable()->addSecond());
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
