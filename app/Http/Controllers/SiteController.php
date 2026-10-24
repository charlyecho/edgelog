<?php

namespace App\Http\Controllers;

use App\Analytics\SitePeriod;
use App\Analytics\SiteSparklines;
use App\Http\Requests\SaveSiteRequest;
use App\Ingest\ShipperSetup;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteController extends Controller
{
    /**
     * List the team's sites.
     */
    public function index(Team $team): View
    {
        Gate::authorize('view', $team);

        $sites = $team->sites()->orderBy('name')->get();

        return view('sites.index', [
            'team' => $team,
            'sites' => $sites,
            'visitors' => SiteSparklines::visitors($sites),
        ]);
    }

    /**
     * Show the form to add a site to the team.
     */
    public function create(Team $team): View
    {
        Gate::authorize('create', [Site::class, $team]);

        return view('sites.create', ['team' => $team]);
    }

    /**
     * Add a site to the team and show its token once.
     */
    public function store(SaveSiteRequest $request, Team $team): RedirectResponse
    {
        $site = $team->sites()->make($request->siteAttributes());
        $token = $site->fillNewToken();
        $site->save();

        return redirect()
            ->route('teams.sites.edit', [$team, $site])
            ->with('status', __('Site added.'))
            ->with('site_token', $token);
    }

    /**
     * Show the site's dashboard.
     */
    public function show(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $period = SitePeriod::choose($site, $choice);

        return view('sites.show', [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $period,
            'previous' => $period->previous(),
        ]);
    }

    /**
     * Show the site's settings: name, URL, token, team and deletion.
     */
    public function edit(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('update', $site);

        $otherTeams = $request->user()->teams
            ->reject(fn (Team $other) => $other->is($team))
            ->filter(fn (Team $other) => $other->membership->role->canManageSites());

        return view('sites.edit', [
            'team' => $team,
            'site' => $site->load('ingestStatus'),
            'otherTeams' => $otherTeams,
            'setup' => new ShipperSetup($site),
        ]);
    }

    /**
     * Change the site's name and URL.
     */
    public function update(SaveSiteRequest $request, Team $team, Site $site): RedirectResponse
    {
        $site->update($request->siteAttributes());

        return back()->with('status', __('Site saved.'));
    }

    /**
     * Delete the site.
     */
    public function destroy(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);

        $site->delete();

        return redirect()->route('teams.sites.index', $team)->with('status', __(':site was deleted.', ['site' => $site->name]));
    }
}
