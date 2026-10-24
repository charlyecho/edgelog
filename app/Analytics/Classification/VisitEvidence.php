<?php

namespace App\Analytics\Classification;

use App\Analytics\PageRule;
use App\Analytics\Patterns;
use App\Ingest\LogHit;

/**
 * The first pass over a day: what can only be known once every request of
 * a visitor is seen.
 *
 * - One probe marks the whole visitor: an address and user agent that asked
 *   for a secret or config file, or sent an attack in a URL, is a scanner
 *   for the whole day, its requests before the probe too.
 * - A browser-like visit (one address and agent, no gap over 30 minutes)
 *   that fetched robots.txt, a sitemap, ads.txt or security.txt without
 *   loading the site's own assets once per page it read is a crawler.
 * - A browser-like visit that read pages and never drew one (no script,
 *   style or framework payload from 5 s before to 30 s after one of its
 *   pages, and no visit of the same address and agent drew one earlier that
 *   day) is a scraper in a browser's clothes.
 * - A browser-like visit with 6 page views or more, all within 2 seconds of
 *   its first, is too fast to be a person.
 *
 * A visit counts whole: every request of a flagged visit is filed under bots.
 */
final class VisitEvidence
{
    public const string SCANNER = 'scanner (asked for secret or config files)';

    public const string CRAWLER = 'browser-like crawler (fetched robots.txt or a sitemap)';

    public const string NOT_RENDERED = 'browser-like, never rendered the page';

    public const string TOO_FAST = 'too fast to be a person';

    private const int PAGE = 1;

    private const int CRAWLER_FILE = 2;

    private const int OWN_ASSET = 4;

    private const int RENDER = 8;

    /**
     * @var array<string, true>
     */
    private array $scanners = [];

    /**
     * Each browser-like visitor's requests, as [time, flags].
     *
     * @var array<string, list<array{0: int, 1: int}>>
     */
    private array $events = [];

    /**
     * Each flagged visit, as [first, last, reason].
     *
     * @var array<string, list<array{0: int, 1: int, 2: string}>>
     */
    private array $flagged = [];

    /**
     * @param  list<string>  $ownHosts
     */
    public function __construct(
        private readonly array $ownHosts,
        private readonly bool $requireRendering = true,
        private readonly int $gapSeconds = 1800,
        private readonly int $tooFastPages = 6,
        private readonly int $tooFastSeconds = 2,
    ) {}

    public static function key(LogHit $hit): string
    {
        return $hit->address."\0".$hit->userAgent;
    }

    /**
     * Take in one request of the day (on the site's own hosts).
     */
    public function add(LogHit $hit): void
    {
        $key = self::key($hit);
        $path = $hit->path();

        if (! isset($this->scanners[$key])
            && (preg_match(Patterns::SECRET_PROBE, $path) === 1 || preg_match(Patterns::PROBE_QUERY, $hit->url) === 1)) {
            $this->scanners[$key] = true;
        }

        if (preg_match(Patterns::BROWSER, $hit->userAgent) !== 1) {
            return;
        }

        $flags = 0;

        if (PageRule::isPage($hit)) {
            $flags |= self::PAGE;
        }

        if (preg_match(Patterns::CRAWLER_PATH, $path) === 1) {
            $flags |= self::CRAWLER_FILE;
        }

        $isAsset = preg_match(Patterns::ASSET, $path) === 1 || preg_match(Patterns::RSC_PAYLOAD, $hit->url) === 1;

        if ($isAsset && $this->referredByOwnSite($hit)) {
            $flags |= self::OWN_ASSET;
        }

        if (preg_match(Patterns::RENDER_ASSET, $path) === 1 || preg_match(Patterns::RSC_PAYLOAD, $hit->url) === 1) {
            $flags |= self::RENDER;
        }

        $this->events[$key][] = [$hit->when->getTimestamp(), $flags];
    }

    /**
     * Decide every visit once the whole day was taken in.
     */
    public function finish(): void
    {
        foreach ($this->events as $key => $events) {
            usort($events, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $renderedEarlier = false;

            foreach ($this->visits($events) as $visit) {
                $reason = $this->judge($visit, $renderedEarlier);

                if ($reason !== null) {
                    $this->flagged[$key][] = [$visit[0][0], $visit[array_key_last($visit)][0], $reason];
                }
            }
        }

        $this->events = [];
    }

    /**
     * Why the request is not a person's, from the whole visit it belongs to, or null.
     */
    public function reasonFor(LogHit $hit): ?string
    {
        $key = self::key($hit);

        if (isset($this->scanners[$key])) {
            return self::SCANNER;
        }

        $time = $hit->when->getTimestamp();

        foreach ($this->flagged[$key] ?? [] as [$first, $last, $reason]) {
            if ($time >= $first && $time <= $last) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $events
     * @return list<list<array{0: int, 1: int}>>
     */
    private function visits(array $events): array
    {
        $visits = [];
        $current = [];

        foreach ($events as $event) {
            if ($current !== [] && $this->gapSeconds < $event[0] - $current[array_key_last($current)][0]) {
                $visits[] = $current;
                $current = [];
            }

            $current[] = $event;
        }

        if ($current !== []) {
            $visits[] = $current;
        }

        return $visits;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $visit
     */
    private function judge(array $visit, bool &$renderedEarlier): ?string
    {
        $pages = array_values(array_filter($visit, fn (array $e) => ($e[1] & self::PAGE) !== 0));
        $renders = array_values(array_filter($visit, fn (array $e) => ($e[1] & self::RENDER) !== 0));
        $ownAssets = count(array_filter($visit, fn (array $e) => ($e[1] & self::OWN_ASSET) !== 0));
        $crawlerFile = array_filter($visit, fn (array $e) => ($e[1] & self::CRAWLER_FILE) !== 0) !== [];

        $rendered = $this->rendered($pages, $renders);
        $drawnBefore = $renderedEarlier;
        $renderedEarlier = $renderedEarlier || $rendered;

        if ($crawlerFile && ! ($ownAssets > 0 && $ownAssets >= count($pages))) {
            return self::CRAWLER;
        }

        if ($this->requireRendering && $pages !== [] && ! $rendered && ! $drawnBefore) {
            return self::NOT_RENDERED;
        }

        if ($this->tooFastPages > 0 && count($pages) >= $this->tooFastPages
            && $pages[array_key_last($pages)][0] - $pages[0][0] <= $this->tooFastSeconds) {
            return self::TOO_FAST;
        }

        return null;
    }

    /**
     * A page was drawn: one of its scripts, styles or payloads was fetched
     * from 5 seconds before to 30 seconds after it.
     *
     * @param  list<array{0: int, 1: int}>  $pages
     * @param  list<array{0: int, 1: int}>  $renders
     */
    private function rendered(array $pages, array $renders): bool
    {
        foreach ($pages as [$pageTime]) {
            foreach ($renders as [$renderTime]) {
                if ($renderTime >= $pageTime - 5 && $renderTime <= $pageTime + 30) {
                    return true;
                }
            }
        }

        return false;
    }

    private function referredByOwnSite(LogHit $hit): bool
    {
        if ($hit->referrer === '') {
            return false;
        }

        $host = strtolower((string) parse_url($hit->referrer, PHP_URL_HOST));

        return $host !== '' && in_array($host, $this->ownHosts, true);
    }
}
