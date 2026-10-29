<?php

namespace App\Analytics;

use App\Models\Site;

/**
 * Paths a site really has that scanners also ask for: its own /admin/, an
 * old site's /wp-content/uploads/, the routes of the /index.php it serves
 * its pages through. A request under one is never a scanner probe, so the
 * people who use them stay people, and what they were answered is never
 * taken for an exposure.
 */
final readonly class OwnPaths extends PathPatterns
{
    public static function of(Site $site): self
    {
        return new self($site->ownPathPatterns());
    }
}
