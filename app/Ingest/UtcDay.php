<?php

namespace App\Ingest;

/**
 * Shipped logs are counted and rotated by UTC day.
 */
final class UtcDay
{
    /**
     * Today's date, in UTC.
     */
    public static function today(): string
    {
        return now('UTC')->toDateString();
    }

    /**
     * The seconds a shipper should wait before trying again after a full day:
     * until midnight UTC, between one minute and one hour.
     */
    public static function retryAfter(): int
    {
        $left = (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay(), true);

        return max(60, min($left, 3600));
    }
}
