<?php

namespace App\Http\Controllers;

use App\Analytics\Retention;
use App\Analytics\SitePeriod;
use App\Analytics\VisitorFlow;
use App\Enums\CountKind;
use App\Enums\SourceType;
use App\Models\Site;
use App\Models\SiteVisit;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteTrafficController extends Controller
{
    /**
     * Visits shown in the list before "Show all".
     */
    public const int VISITS_SHOWN = 50;

    /**
     * The most visits the list shows at once.
     */
    public const int VISITS_MAX = 5000;

    /**
     * The visit list's filters, each a column of the kept visits.
     */
    public const array FILTERS = ['source', 'device', 'country', 'visitor'];

    /**
     * Show what the period's visits looked like: their flow, each visit,
     * new and returning visitors, how engaged they were and where they ended.
     */
    public function visitors(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $period = SitePeriod::choose($site, $choice);
        $depth = in_array((int) $request->query('flow_depth'), VisitorFlow::DEPTHS, true) ? (int) $request->query('flow_depth') : 4;
        $start = $request->query('flow_start') === 'via' ? 'via' : 'source';
        $filters = array_filter($request->only(self::FILTERS), fn ($value) => is_string($value) && $value !== '');
        $search = trim((string) $request->query('q'));

        $flow = new VisitorFlow($period->visits()->toBase()->get(['source', 'via', 'trail'])->map(fn (object $visit) => [
            'start' => $start === 'via' && $visit->via !== '' ? $visit->via : (SourceType::tryFrom($visit->source)?->label() ?? $visit->source),
            'trail' => json_decode($visit->trail, true) ?: [],
        ])->all(), $depth, $start);

        $listed = $this->filtered($period->visits(), $filters, $search);
        $matching = (clone $listed)->count();
        $showAll = $request->boolean('all');

        return view('sites.traffic.visitors', [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $period,
            'flow' => $flow,
            'chips' => $this->chips($period),
            'filters' => $filters,
            'search' => $search,
            'visits' => $listed->limit($showAll ? self::VISITS_MAX : self::VISITS_SHOWN)->get(),
            'matching' => $matching,
            'keptDays' => $period->daysWithVisits(),
        ]);
    }

    /**
     * Show where visitors came from: by source type, social network,
     * referring site and campaign, then how each referrer's and each
     * utm_ tag's visits went.
     */
    public function sources(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'sources', fn (SitePeriod $period) => [
            'list' => in_array($request->query('list'), ['type', 'social', 'referrer', 'campaign'], true) ? $request->query('list') : 'type',
            'utm' => in_array($request->query('utm'), ['source', 'medium', 'campaign', 'term', 'content'], true) ? $request->query('utm') : 'source',
        ]);
    }

    /**
     * Show the site's pages: when each was first seen, how each did in the
     * period, where visits started and what people read.
     */
    public function pages(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'pages', fn (SitePeriod $period) => [
            'lastWeek' => SitePeriod::lastDays($site, 7),
            'lastMonth' => SitePeriod::lastDays($site, 30),
            'published' => $site->pages()->whereNotNull('first_read_on')
                ->where(fn (Builder $query) => $query->where('first_read_on', '>=', $period->first->toDateString())
                    ->orWhere('first_google_on', '>=', $period->first->toDateString()))
                ->get()
                ->filter(fn ($page) => $page->publishedOn()?->betweenIncluded($period->first, $period->last))
                ->sortBy([
                    fn ($a, $b) => $b->publishedOn() <=> $a->publishedOn(),
                    fn ($a, $b) => strcmp($a->path, $b->path),
                ])
                ->values(),
        ]);
    }

    /**
     * Show the visitors' devices, browsers, systems, countries and cities.
     */
    public function places(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'places');
    }

    /**
     * Show when visitors come: each day hour by hour, and every hour together.
     */
    public function time(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'time', fn (SitePeriod $period) => [
            'hours' => $period->dailyCounts(CountKind::VisitHour),
        ]);
    }

    /**
     * Show the visitors first seen in each recent week (or day) and the
     * share back in each one after. Its own period: the bar's does not apply.
     */
    public function retention(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $grain = $request->query('by') === 'day' ? 'day' : 'week';
        $readers = $request->query('back') === 'read';

        return view('sites.traffic.retention', [
            'team' => $team,
            'site' => $site,
            'grain' => $grain,
            'readers' => $readers,
            'retention' => new Retention($site, $grain, $readers),
        ]);
    }

    /**
     * A tab that reads the period the bar picked.
     *
     * @param  ?callable(SitePeriod): array<string, mixed>  $more
     */
    private function tab(Request $request, Team $team, Site $site, string $tab, ?callable $more = null): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $period = SitePeriod::choose($site, $choice);

        return view("sites.traffic.{$tab}", [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $period,
            ...($more === null ? [] : $more($period)),
        ]);
    }

    /**
     * @param  Builder<SiteVisit>  $visits
     * @param  array<string, string>  $filters
     * @return Builder<SiteVisit>
     */
    private function filtered(Builder $visits, array $filters, string $search): Builder
    {
        foreach ($filters as $filter => $value) {
            match ($filter) {
                'visitor' => $visits->where('returning', $value === 'returning'),
                default => $visits->where($filter, $value),
            };
        }

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $visits->where(fn (Builder $query) => $query->whereLike('trail', $like)->orWhereLike('via', $like)->orWhereLike('source', $like)->orWhereLike('campaign', $like));
        }

        return $visits;
    }

    /**
     * How many kept visits of the period each filter value has, most first.
     *
     * @return array<string, array<string, int>>
     */
    private function chips(SitePeriod $period): array
    {
        $chips = [];

        foreach (['source', 'device', 'country'] as $column) {
            $chips[$column] = $period->visits()->reorder()->toBase()->groupBy($column)->selectRaw("{$column} as value, count(*) as visits")
                ->orderByDesc('visits')->limit(8)->pluck('visits', 'value')->map(fn ($visits) => (int) $visits)->all();
        }

        $chips['visitor'] = $period->visits()->reorder()->whereNotNull('returning')->toBase()->groupBy('returning')
            ->selectRaw('"returning" as value, count(*) as visits')->pluck('visits', 'value')
            ->mapWithKeys(fn ($visits, $returning) => [$returning ? 'returning' : 'new' => (int) $visits])->sortKeys()->all();

        return $chips;
    }
}
