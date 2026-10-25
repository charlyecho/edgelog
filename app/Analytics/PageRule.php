<?php

namespace App\Analytics;

use App\Ingest\LogHit;

/**
 * Whether a request is something a person could have read: separate from
 * who asked.
 *
 * Only a 2xx or 304 answer to a GET or HEAD, for neither a file (an asset,
 * a feed, a document), nor an endpoint a page calls while it is read, nor a
 * framework's data fetch, nor a path the site says is not a page (NotPages).
 * A redirect is not a page: the page it leads to is logged on its own. A
 * 304 is: the browser shows its cached copy.
 */
final class PageRule
{
    public static function isPage(LogHit $hit, ?NotPages $notPages = null): bool
    {
        if (! in_array($hit->method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (! (($hit->status >= 200 && $hit->status < 300) || $hit->status === 304)) {
            return false;
        }

        $path = $hit->path();

        if (preg_match(Patterns::ASSET, $path) === 1 || preg_match(Patterns::BEACON, $path) === 1) {
            return false;
        }

        if ($notPages?->matches($path)) {
            return false;
        }

        if (preg_match(Patterns::RSC_PAYLOAD, $hit->url) === 1) {
            return self::isSoftNavigation($hit);
        }

        return true;
    }

    /**
     * A Next.js navigation (a click inside the site, not a prefetch), told
     * apart only when the line records the Next-Router-Prefetch header.
     */
    public static function isSoftNavigation(LogHit $hit): bool
    {
        return $hit->method === 'GET'
            && preg_match(Patterns::RSC_QUERY, $hit->url) === 1
            && $hit->rscPrefetch !== null
            && in_array($hit->rscPrefetch, ['', '-'], true);
    }
}
