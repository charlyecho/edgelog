<?php

namespace App\Http\Controllers;

use App\Analytics\Crawlers\CrawlerPeriod;
use App\Analytics\Crawlers\CrawlHealth;
use App\Analytics\Crawlers\RobotsTxt;
use App\Analytics\Crawlers\RobotsVerdicts;
use App\Analytics\SitePeriod;
use App\Enums\CountKind;
use App\Jobs\FetchRobotsTxt;
use App\Jobs\FetchSitemap;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteAiController extends Controller
{
    /**
     * The days llms.txt reads the most read pages from.
     */
    public const int LLMS_DAYS = 90;

    /**
     * AI working for someone waiting on an answer, and the visitors AI sent.
     */
    public function assistants(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'assistants');
    }

    /**
     * Everything with nobody waiting: AI collecting for training, search engines and bots.
     */
    public function crawlers(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'crawlers', function (SitePeriod $period, CrawlerPeriod $crawlers) use ($request) {
            $bot = array_key_exists((string) $request->query('bot'), $period->counts(CountKind::BotAgent)) ? (string) $request->query('bot') : null;

            return ['bot' => $bot, 'botPaths' => $crawlers->botPaths($bot)];
        });
    }

    /**
     * Which of the site's pages each crawler fetched, which it never did, and
     * the problems crawlers hit. Its own window: the last 7 or 30 whole days.
     */
    public function crawl(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $window = (int) $request->query('window') === 7 ? 7 : 30;
        $health = new CrawlHealth($site, $window);
        $crawler = array_key_exists((string) $request->query('crawler'), $health->crawlers) ? $request->query('crawler') : array_key_first($health->crawlers);

        return view('sites.ai.crawl', ['team' => $team, 'site' => $site, 'health' => $health, 'crawler' => $crawler, 'sitemap' => $site->sitemap]);
    }

    /**
     * Read the site's robots.txt and sitemaps again, now.
     */
    public function refreshSitemap(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        FetchRobotsTxt::dispatchSync($site);
        FetchSitemap::dispatchSync($site);

        return back()->with('status', __('Sitemap read again.'));
    }

    /**
     * What the site's robots.txt says to each crawler, and whether it kept to it.
     */
    public function rules(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'rules');
    }

    /**
     * Read the site's robots.txt again, now.
     */
    public function refreshRobots(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        FetchRobotsTxt::dispatchSync($site);

        return back()->with('status', __('robots.txt read again.'));
    }

    /**
     * A suggested llms.txt, from the pages people and assistants read most.
     */
    public function llms(Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $period = SitePeriod::lastDays($site, self::LLMS_DAYS);

        return view('sites.ai.llms', ['team' => $team, 'site' => $site, 'period' => $period, 'llms' => $this->llmsTxt($site, $period)]);
    }

    public function downloadLlms(Team $team, Site $site): Response
    {
        Gate::authorize('view', $site);

        return response($this->llmsTxt($site, SitePeriod::lastDays($site, self::LLMS_DAYS)), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="llms.txt"',
        ]);
    }

    /**
     * The most read pages by section (their first path segment), then the Markdown copies.
     */
    private function llmsTxt(Site $site, SitePeriod $period): string
    {
        $reads = $period->counts(CountKind::PeoplePage);

        foreach ($period->counts(CountKind::EnginePage) as $path => $count) {
            $reads[$path] = ($reads[$path] ?? 0) + $count;
        }

        unset($reads[CountKind::OTHERS]);
        arsort($reads);

        $paths = array_values(array_filter(array_map('strval', array_keys(array_slice($reads, 0, 60, true))),
            fn (string $path) => preg_match('~^/(?:robots\.txt|llms(?:-full)?\.txt|favicon\.ico)$|sitemap~i', $path) !== 1));
        $markdown = array_values(array_filter($paths, fn (string $path) => preg_match('~\.(?:md|mdx|markdown)$~i', $path) === 1));
        $segment = fn (string $path) => explode('/', trim($path, '/'))[0];

        // A first segment with deeper pages is a section; a page alone at the top is a main page.
        $deep = array_flip(array_map($segment, array_filter($paths, fn (string $path) => substr_count(trim($path, '/'), '/') > 0)));
        $sections = [];

        foreach (array_diff($paths, $markdown) as $path) {
            $section = isset($deep[$segment($path)]) ? ucfirst(str_replace(['-', '_'], ' ', $segment($path))) : __('Main pages');
            $sections[$section][] = $path;
        }

        $base = rtrim($site->url, '/');
        $lines = ["# {$site->host()}", '', '> '.__('Replace this line with one or two sentences: what :site offers and who it is for.', ['site' => $site->host()])];

        foreach ([...$sections, ...($markdown === [] ? [] : [__('Markdown copies') => $markdown])] as $section => $paths) {
            $lines[] = '';
            $lines[] = "## {$section}";
            $lines[] = '';

            foreach (array_slice($paths, 0, 15) as $path) {
                $lines[] = "- [{$path}]({$base}{$path})";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A tab that reads the period the bar picked.
     */
    /**
     * @param  ?callable(SitePeriod, CrawlerPeriod): array<string, mixed>  $more
     */
    private function tab(Request $request, Team $team, Site $site, string $tab, ?callable $more = null): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $period = SitePeriod::choose($site, $choice);
        $crawlers = new CrawlerPeriod($period);
        $robots = $site->robots;

        return view("sites.ai.{$tab}", [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $period,
            'crawlers' => $crawlers,
            'robots' => $robots,
            'verdicts' => new RobotsVerdicts($robots?->body === null ? null : RobotsTxt::parse($robots->body), $crawlers->split(CountKind::CrawlerPath)),
            ...($more === null ? [] : $more($period, $crawlers)),
        ]);
    }
}
