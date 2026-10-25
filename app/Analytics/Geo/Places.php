<?php

namespace App\Analytics\Geo;

/**
 * Tells where a visitor's address is.
 */
interface Places
{
    /**
     * The place of an address. A country the log line carries (a CDN's own
     * header) wins over the file's; a city is kept only when it lies in that country.
     */
    public function of(string $address, string $loggedCountry = ''): Place;

    /**
     * Determine if any file is there to look addresses up in.
     */
    public function isAvailable(): bool;
}
