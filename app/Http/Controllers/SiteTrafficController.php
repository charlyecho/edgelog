<?php

namespace App\Http\Controllers;

use App\Analytics\SitePeriod;
use App\Analytics\VisitorFlow;
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
