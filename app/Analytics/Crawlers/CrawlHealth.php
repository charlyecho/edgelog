<?php

namespace App\Analytics\Crawlers;

use App\Analytics\Labels;
use App\Analytics\Patterns;
use App\Analytics\SitePeriod;
use App\Enums\CountKind;
use App\Models\Site;

/**
 * Which of the site's pages each crawler fetched over the last days, which
 * it never did, which pages it fetched that the sitemap does not list, and
 * the pages that answer crawlers with an error, a redirect, slowly or with
 * a large body. Google and Bing, and every AI crawler and AI user fetcher
 * by name, from the paths kept by crawler each day.
 */
final class CrawlHealth
{
    public const array WINDOWS = [7, 30];

    public const array ISSUES = ['gone', 'error', 'redirect', 'slow', 'large'];

    /**
     * The sitemap's pages, by their path without a trailing slash.
     *
     * @var array<string, string>
     */
    public readonly array $sitemap;

    /**
     * Each crawler's figures, most requests first.
     *
     * @var array<string, array{requests: int, pages: array<string, int>, issues: array<string, int>, fetched_daily: list<?int>, user_fetcher: bool}>
     */
    public array $crawlers = [];

    /**
     * Each page every crawler fetched: its requests by crawler, its days, its last day, its issues.
     *
     * @var array<string, array{requests: int, by: array<string, int>, days: array<string, true>, last: string, issues: array<string, int>, page: bool}>
     */
    public array $pages = [];

    public readonly SitePeriod $period;

    public function __construct(public readonly Site $site, public readonly int $window = 30)
    {
        $this->period = SitePeriod::lastDays($site, $window);
        $this->sitemap = collect($site->sitemap?->paths ?? [])->mapWithKeys(fn (string $path) => [self::key($path) => $path])->all();

        $days = $this->period->dailyCounts(CountKind::CrawlerPath);
        $issues = $this->period->dailyCounts(CountKind::CrawlerIssue);
        $dates = array_keys($this->period->days);
        $verified = $this->period->verified();
        $fetched = [];

        foreach ($days as $date => $counts) {
            foreach ($counts as $key => $count) {
                [$name, $path] = array_pad(explode(CrawlerCollector::SEPARATOR, (string) $key, 2), 2, null);

                if ($path === null || ($label = self::label($name)) === null) {
                    continue;
                }

                $crawler = &$this->crawler($label, $name);
                $crawler['requests'] += $count;
                $crawler['pages'][$path] = ($crawler['pages'][$path] ?? 0) + $count;
                unset($crawler);

                if (isset($this->sitemap[self::key($path)])) {
                    $fetched[$label][$date][self::key($path)] = true;
                }

                $page = $this->pages[$path] ?? ['requests' => 0, 'by' => [], 'days' => [], 'last' => $date, 'issues' => [], 'page' => CrawlerCollector::fetchKind($path) === 'page'];
                $page['requests'] += $count;
                $page['by'][$label] = ($page['by'][$label] ?? 0) + $count;
                $page['days'][$date] = true;
                $page['last'] = max($page['last'], $date);
                $this->pages[$path] = $page;
            }
        }

        foreach ($issues as $counts) {
            foreach ($counts as $key => $count) {
                [$name, $path, $issue] = array_pad(explode(CrawlerCollector::SEPARATOR, (string) $key, 3), 3, null);

                if ($issue === null || ($label = self::label($name)) === null || ! isset($this->pages[$path])) {
                    continue;
                }

                $this->pages[$path]['issues'][$issue] = ($this->pages[$path]['issues'][$issue] ?? 0) + $count;
                $this->crawlers[$label]['issues'][$issue] = ($this->crawlers[$label]['issues'][$issue] ?? 0) + $count;
            }
        }

        foreach ($this->crawlers as $label => &$crawler) {
            $crawler['fetched_daily'] = array_map(
                fn (string $date) => isset($verified[$date]) ? count($fetched[$label][$date] ?? []) : null,
                $dates,
            );
        }
        unset($crawler);

        uasort($this->crawlers, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);
    }

    /**
     * The crawler a name is shown as: Google and Bing by their engine, AI
     * crawlers and fetchers by their own name, any other none.
     */
    public static function label(string $name): ?string
    {
        if (array_key_exists($name, self::$labels)) {
            return self::$labels[$name];
        }

        return self::$labels[$name] = match (true) {
            str_starts_with($name, 'Googlebot') => 'Google',
            strcasecmp($name, 'bingbot') === 0 => 'Bing',
            Labels::isUserFetcher($name) || preg_match(Patterns::AI_TRAINING, $name) === 1 => self::$spellings[strtolower($name)] ??= $name,
            default => null,
        };
    }

    /**
     * The label of each name already read: the same names come back on every day.
     *
     * @var array<string, ?string>
     */
    private static array $labels = [];

    /**
     * One spelling per crawler, whatever case its user agent writes its name in.
     *
     * @var array<string, string>
     */
    private static array $spellings = [];

    /**
     * The sitemap's pages the crawler fetched at least once.
     *
     * @return array<string, string>
     */
    public function covered(string $crawler): array
    {
        $fetched = [];

        foreach (array_keys($this->crawlers[$crawler]['pages'] ?? []) as $path) {
            $fetched[self::key((string) $path)] = true;
        }

        return array_intersect_key($this->sitemap, $fetched);
    }

    /**
     * The sitemap's pages the crawler never fetched.
     *
     * @return list<string>
     */
    public function neverFetched(string $crawler): array
    {
        return array_values(array_diff_key($this->sitemap, $this->covered($crawler)));
    }

    /**
     * The pages the crawler fetched as a page that the sitemap does not list.
     *
     * @return list<string>
     */
    public function notInSitemap(?string $crawler = null): array
    {
        return array_keys(array_filter($this->pages, fn (array $page, string $path) => $page['page']
            && ! isset($this->sitemap[self::key($path)])
            && ($crawler === null || isset($page['by'][$crawler]))
            && $page['requests'] > array_sum(array_intersect_key($page['issues'], array_flip(['gone', 'error', 'redirect']))), ARRAY_FILTER_USE_BOTH));
    }

    public function inSitemap(string $path): bool
    {
        return isset($this->sitemap[self::key($path)]);
    }

    /**
     * The pages that hit an issue, most first.
     *
     * @return array<string, int>
     */
    public function withIssue(string $issue): array
    {
        $pages = array_filter(array_map(fn (array $page) => $page['issues'][$issue] ?? 0, $this->pages));
        arsort($pages);

        return $pages;
    }

    /**
     * @return array{requests: int, pages: array<string, int>, issues: array<string, int>, fetched_daily: list<?int>, user_fetcher: bool}
     */
    private function &crawler(string $label, string $name): array
    {
        $this->crawlers[$label] ??= ['requests' => 0, 'pages' => [], 'issues' => [], 'fetched_daily' => [], 'user_fetcher' => Labels::isUserFetcher($name)];

        return $this->crawlers[$label];
    }

    /**
     * A path as the sitemap and the log both name it: /pricing and /pricing/ are one page.
     */
    private static function key(string $path): string
    {
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
