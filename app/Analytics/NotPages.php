<?php

namespace App\Analytics;

use App\Models\Site;

/**
 * A site's paths that are not pages: what its own pages call while they are
 * read (/user/home_ajax/2026-10-24, loaded by /user/home). Like /api/, a
 * request to one is part of its visit, never a page of it.
 */
final readonly class NotPages extends PathPatterns
{
    public static function of(Site $site): self
    {
        return new self(array_values($site->not_pages ?? []));
    }
}
