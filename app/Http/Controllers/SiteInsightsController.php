<?php

namespace App\Http\Controllers;

use App\Analytics\Insights\Insights;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteInsightsController extends Controller
{
    /**
     * What changed, in plain words: what is working, what dropped, what is
     * new or gone, what looks unusual and what is worth fixing.
     */
    public function __invoke(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $findings = (new Insights($site))->all();
        $counts = array_map(fn (string $window) => count(array_filter($findings, fn (array $finding) => $finding['window'] === $window)), array_combine(Insights::WINDOWS, Insights::WINDOWS));
        $window = in_array($request->query('window'), Insights::WINDOWS, true)
            ? $request->query('window')
            : (collect(['7', '1', '30'])->first(fn (string $window) => $counts[$window] > 0) ?? '7');
        $shown = array_values(array_filter($findings, fn (array $finding) => $finding['window'] === $window));

        return view('sites.insights', [
            'team' => $team,
            'site' => $site,
            'window' => $window,
            'counts' => $counts,
            'findings' => $shown,
            'cards' => Insights::fold($shown),
        ]);
    }
}
