<?php

namespace App\Analytics;

use App\Ingest\LogHit;

/**
 * The scripts a site serves its pages through, read from a day of its log:
 * a CodeIgniter or Laravel app without URL rewriting answers /index.php/shop
 * and /index.php/contact, which the probe patterns take for scanners asking
 * for PHP files.
 *
 * A script is the site's own when, in one day, at least MIN_ROUTES distinct
 * routes under it were answered (2xx or 3xx) to at least MIN_ADDRESSES
 * addresses that came from the site's own pages (an own referrer). A scanner
 * asking a site that has no such script is answered 404, and does not come
 * from its pages.
 */
final class ServedScripts
{
    public const int MIN_ROUTES = 10;

    public const int MIN_ADDRESSES = 3;

    /**
     * A script at the root, then a route under it.
     */
    private const string ROUTE = '~^(/[A-Za-z0-9_.-]+\.php)/(.+)$~';

    /**
     * Each script's routes and addresses seen: [script][route|address] => true.
     *
     * @var array<string, array{routes: array<string, true>, addresses: array<string, true>}>
     */
    private array $seen = [];

    /**
     * @param  list<string>  $ownHosts  the site's hosts, as a referrer names them
     */
    public function __construct(private readonly array $ownHosts) {}

    public function add(LogHit $hit): void
    {
        if ($hit->status < 200 || $hit->status >= 400 || preg_match(self::ROUTE, $hit->path(), $match) !== 1) {
            return;
        }

        $referrerHost = strtolower((string) parse_url($hit->referrer, PHP_URL_HOST));

        if ($referrerHost === '' || ! in_array($referrerHost, $this->ownHosts, true)) {
            return;
        }

        $this->seen[$match[1]]['routes'][$match[2]] = true;
        $this->seen[$match[1]]['addresses'][$hit->address] = true;
    }

    /**
     * The scripts the day shows the site serves its pages through.
     *
     * @return list<string>
     */
    public function found(): array
    {
        return array_keys(array_filter($this->seen, fn (array $script) => count($script['routes']) >= self::MIN_ROUTES
            && count($script['addresses']) >= self::MIN_ADDRESSES));
    }

    /**
     * The own paths a script stands for: itself, and every route under it.
     *
     * @return list<string>
     */
    public static function paths(string $script): array
    {
        return [$script, "{$script}/*"];
    }
}
