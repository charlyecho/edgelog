<?php

namespace App\Jobs;

use App\Analytics\Crawlers\RobotsTxt;
use App\Analytics\SiteHosts;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read a site's sitemaps from outside, as a crawler would: those its
 * robots.txt names, else /sitemap.xml, a sitemap index's own sitemaps
 * included, gzip or not. Only public addresses, only the site's own hosts,
 * with a size, a count and a time limit. Keeps the paths they list.
 */
class FetchSitemap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_SITEMAPS = 20;

    public const int MAX_PATHS = 50_000;

    public const int MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(public Site $site) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(): void
    {
        $hosts = SiteHosts::of($this->site)->hosts;
        $robots = $this->site->robots()->first();
        $queue = array_values(array_filter(
            RobotsTxt::parse((string) $robots?->body)->sitemaps,
            fn (string $url) => in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true),
        )) ?: [rtrim($this->site->url, '/').'/sitemap.xml'];

        $read = [];
        $paths = [];
        $error = null;

        while ($queue !== [] && count($read) < self::MAX_SITEMAPS && count($paths) < self::MAX_PATHS) {
            $url = array_shift($queue);

            if (isset($read[$url])) {
                continue;
            }

            $read[$url] = true;

            try {
                [$index, $locations] = $this->read($url);
            } catch (Throwable $exception) {
                $error ??= substr("{$url}: {$exception->getMessage()}", 0, 250);

                continue;
            }

            foreach ($locations as $location) {
                if (! in_array(strtolower((string) parse_url($location, PHP_URL_HOST)), $hosts, true)) {
                    continue;
                }

                if ($index) {
                    $queue[] = $location;
                } else {
                    $paths[parse_url($location, PHP_URL_PATH) ?: '/'] = true;
                }
            }
        }

        $this->site->sitemap()->updateOrCreate([], [
            'sources' => array_keys($read),
            'paths' => array_slice(array_keys($paths), 0, self::MAX_PATHS),
            'error' => $paths === [] ? ($error ?? __('No page listed.')) : $error,
            'fetched_at' => now(),
        ]);
    }

    /**
     * Whether a sitemap is an index, and the addresses it lists.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function read(string $url): array
    {
        if (! FetchRobotsTxt::isPublic((string) parse_url($url, PHP_URL_HOST))) {
            throw new RuntimeException(__('The site\'s address is not a public one.'));
        }

        $response = Http::timeout(20)->connectTimeout(5)->withoutRedirecting()
            ->withUserAgent('Mozilla/5.0 (compatible; Edgelog/1.0; sitemap check)')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(__('answered :status', ['status' => $response->status()]));
        }

        $body = substr($response->body(), 0, self::MAX_BYTES);

        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) @gzdecode($body, self::MAX_BYTES);
        }

        $xml = @simplexml_load_string($body, options: LIBXML_NONET | LIBXML_NOCDATA);

        if ($xml === false) {
            throw new RuntimeException(__('not a sitemap'));
        }

        return [
            strtolower($xml->getName()) === 'sitemapindex',
            array_map(fn ($location) => trim((string) $location), $xml->xpath('//*[local-name()="loc"]') ?: []),
        ];
    }
}
