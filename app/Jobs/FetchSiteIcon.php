<?php

namespace App\Jobs;

use App\Models\Site;
use DOMDocument;
use DOMElement;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read a site's icon from outside, as a browser would find it: the icons its
 * home page names, the largest first, then /favicon.ico. Public addresses
 * only, redirects checked one by one, a size and a time limit.
 */
class FetchSiteIcon implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The most of the home page that is read for its icons.
     */
    public const int MAX_PAGE_BYTES = 512 * 1024;

    /**
     * The most redirects followed for one address.
     */
    public const int MAX_REDIRECTS = 3;

    /**
     * The link relations that name an icon.
     */
    private const array ICON_RELATIONS = ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'];

    /**
     * @param  bool  $replaceUploaded  Whether an uploaded icon is replaced too (only when asked for).
     */
    public function __construct(public Site $site, public bool $replaceUploaded = false) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    /**
     * Keep the first icon that can be read, and say if one was.
     */
    public function handle(): bool
    {
        if ($this->site->icon_uploaded && ! $this->replaceUploaded) {
            return false;
        }

        $homeUrl = $this->site->url;
        $home = $this->get($homeUrl);
        $candidates = $home?->successful() ? self::iconLinks(substr($home->body(), 0, self::MAX_PAGE_BYTES), $homeUrl) : [];
        $candidates[] = (string) UriResolver::resolve(new Uri($homeUrl), new Uri('/favicon.ico'));

        foreach (array_unique($candidates) as $url) {
            $icon = $this->get($url);

            if ($icon?->successful() && $this->site->storeIcon($icon->body(), uploaded: false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an address, following redirects only to public addresses; null when it cannot be read.
     *
     * @param  string  $url  The address, changed to the one that answered.
     */
    private function get(string &$url): ?Response
    {
        try {
            for ($hops = 0; $hops <= self::MAX_REDIRECTS; $hops++) {
                if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || ! FetchRobotsTxt::isPublic((string) parse_url($url, PHP_URL_HOST))) {
                    return null;
                }

                $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                    ->withUserAgent('Mozilla/5.0 (compatible; Edgelog/1.0; icon check)')
                    ->get($url);

                if (! $response->redirect() || ! $response->header('Location')) {
                    return $response;
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Get the icons a page names, as absolute addresses, the best first:
     * a drawing, then the largest.
     *
     * @return list<string>
     */
    public static function iconLinks(string $html, string $pageUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $base = new Uri($pageUrl);
        $baseElement = $document->getElementsByTagName('base')->item(0);

        if ($baseElement instanceof DOMElement && $baseElement->getAttribute('href') !== '') {
            $base = UriResolver::resolve($base, new Uri($baseElement->getAttribute('href')));
        }

        $icons = [];

        foreach ($document->getElementsByTagName('link') as $link) {
            $relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
            $href = trim($link->getAttribute('href'));

            if (array_intersect($relations, self::ICON_RELATIONS) === [] || $href === '' || str_starts_with(strtolower($href), 'data:')) {
                continue;
            }

            try {
                $url = (string) UriResolver::resolve($base, new Uri($href));
            } catch (Throwable) {
                continue;
            }

            $icons[$url] = max($icons[$url] ?? 0, self::iconScore($link, $relations, $url));
        }

        arsort($icons);

        return array_keys($icons);
    }

    /**
     * Rank an icon link: a drawing scales to any size, otherwise the largest side it declares.
     *
     * @param  list<string>  $relations
     */
    private static function iconScore(DOMElement $link, array $relations, string $url): int
    {
        if ($link->getAttribute('type') === 'image/svg+xml' || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.svg')) {
            return 10000;
        }

        $sizes = strtolower($link->getAttribute('sizes'));

        if ($sizes === 'any') {
            return 10000;
        }

        preg_match_all('/(\d+)x(\d+)/', $sizes, $matches);

        if ($matches[1] !== []) {
            return max(array_map('intval', $matches[1]));
        }

        return in_array('icon', $relations, true) ? 32 : 180;
    }
}
