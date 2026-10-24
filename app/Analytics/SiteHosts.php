<?php

namespace App\Analytics;

use App\Ingest\LogHit;
use App\Models\Site;

/**
 * The host names a site's figures are about: its URL's host, with and
 * without "www.". A line with no host (the combined format logs none) is
 * counted too, as unfiltered.
 */
final readonly class SiteHosts
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(public array $hosts) {}

    public static function of(Site $site): self
    {
        $host = strtolower($site->host());
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return new self(array_values(array_unique([$host, $bare, "www.{$bare}"])));
    }

    public function covers(LogHit $hit): bool
    {
        return $hit->host === '' || in_array($hit->host, $this->hosts, true);
    }
}
