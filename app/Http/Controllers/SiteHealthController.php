<?php

namespace App\Http\Controllers;

use App\Analytics\Health\HealthCollector;
use App\Analytics\Health\HealthPeriod;
use App\Analytics\Live\RecentRequests;
use App\Analytics\Security\SecurityPeriod;
use App\Analytics\SitePeriod;
use App\Enums\TrafficGroup;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SiteHealthController extends Controller
{
    /**
     * Rows of the request log shown a page.
     */
    public const int PER_PAGE = 100;

    /**
     * The request log's quick views: which rows each keeps.
     */
    public const array SHOWS = ['all', 'no_bots', 'people', 'errors', 'ai'];

    /**
     * What the server answered each group, its errors, how fast it answered
     * people's page views, and whether the site was up.
     */
    public function health(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'health');
    }

    /**
     * Every request of the last hours, newest first, with the rule that filed it.
     */
    public function requests(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $log = RecentRequests::latest($site);
        $filters = [
            'show' => in_array($request->query('show'), self::SHOWS, true) ? $request->query('show') : 'all',
            'group' => TrafficGroup::tryFrom((string) $request->query('group')),
            'status' => in_array($request->query('status'), HealthCollector::STATUSES, true) ? $request->query('status') : null,
            'method' => preg_match('/^[A-Z]{1,10}$/', (string) $request->query('method')) === 1 ? $request->query('method') : null,
            'q' => Str::limit(trim((string) $request->query('q')), 200, ''),
        ];

        $rows = array_values(array_filter($log['rows'], fn (array $row) => $this->keeps($row, $filters)));
        $page = max(1, (int) $request->query('page', 1));

        return view('sites.site.requests', [
            'team' => $team,
            'site' => $site,
            'log' => $log,
            'filters' => $filters,
            'methods' => array_values(array_unique(array_column($log['rows'], 'method'))),
            'rows' => new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]),
        ]);
    }

    /**
     * The pages people could not find, where they came from and whether each
     * is fixed; and the missing pages crawlers and scanners asked for.
     */
    public function notFound(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'not-found', fn (HealthPeriod $health) => [
            'security' => new SecurityPeriod($health->period),
        ]);
    }

    /**
     * How fast the server answered people's page views: by day and by page.
     */
    public function speed(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'speed');
    }

    /**
     * Whether the figures can be trusted: each day's count and audit, what
     * can be seen of the log being whole, and when each part last ran.
     */
    public function quality(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'quality', fn (HealthPeriod $health) => [
            'security' => new SecurityPeriod($health->period),
            'files' => $this->logFiles($site),
        ]);
    }

    /**
     * A tab that reads the period the bar picked.
     *
     * @param  ?callable(HealthPeriod): array<string, mixed>  $more
     */
    private function tab(Request $request, Team $team, Site $site, string $tab, ?callable $more = null): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $health = new HealthPeriod(SitePeriod::choose($site, $choice));

        return view("sites.site.{$tab}", [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $health->period,
            'health' => $health,
            ...($more === null ? [] : $more($health)),
        ]);
    }

    /**
     * Determine if a row of the request log passes the filters.
     *
     * @param  array<string, mixed>  $row
     * @param  array{show: string, group: ?TrafficGroup, status: ?string, method: ?string, q: string}  $filters
     */
    private function keeps(array $row, array $filters): bool
    {
        $group = $row['group'];

        $shown = match ($filters['show']) {
            'no_bots' => ! in_array($group, [TrafficGroup::Bots->value, TrafficGroup::SearchEngines->value, TrafficGroup::AnswerEngines->value], true),
            'people' => $group === TrafficGroup::People->value,
            'errors' => $row['status'] >= 400,
            'ai' => $group === TrafficGroup::AnswerEngines->value || $row['cited'] !== '',
            default => true,
        };

        if (! $shown
            || ($filters['group'] !== null && $group !== $filters['group']->value)
            || ($filters['status'] !== null && HealthCollector::statusClass($row['status']) !== $filters['status'])
            || ($filters['method'] !== null && $row['method'] !== $filters['method'])) {
            return false;
        }

        if ($filters['q'] === '') {
            return true;
        }

        foreach ([$row['address'], $row['path'], $row['referrer'], RecentRequests::rule($row)] as $field) {
            if (stripos($field, $filters['q']) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site's log files kept on this server, newest first.
     *
     * @return list<array{name: string, bytes: int, modified: int}>
     */
    private function logFiles(Site $site): array
    {
        $files = glob(IngestLog::for($site)->folder().'/'.IngestLog::BASE.'*') ?: [];
        $rows = array_map(fn (string $file) => ['name' => basename($file), 'bytes' => (int) filesize($file), 'modified' => (int) filemtime($file)], $files);
        usort($rows, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

        return $rows;
    }
}
