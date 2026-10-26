<?php

namespace App\Http\Controllers;

use App\Analytics\SitePeriod;
use App\Analytics\SiteSparklines;
use App\Enums\IntegrationTool;
use App\Http\Requests\SaveSiteRequest;
use App\Ingest\ShipperSetup;
use App\Jobs\FetchSiteIcon;
use App\Jobs\RefreshToday;
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
     * Add a site to the team, show its token once and read its icon.
     */
    public function store(SaveSiteRequest $request, Team $team): RedirectResponse
    {
        $site = $team->sites()->make($request->siteAttributes());
        $token = $site->fillNewToken();
        $site->save();

        FetchSiteIcon::dispatch($site);

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

        $choice = in_array($request->query('days'), [SitePeriod::TODAY, ...SitePeriod::CHOICES], true) ? $request->query('days') : '30';
        $counted = $choice === SitePeriod::TODAY ? RefreshToday::latest($site) : null;
        $today = now($site->timezone)->startOfDay()->toImmutable();
        $period = $choice === SitePeriod::TODAY
            ? SitePeriod::live($site, $counted['figures'] ?? null) ?? new SitePeriod($site, $today, $today)
            : SitePeriod::choose($site, $choice);

        return view('sites.show', [
            'countedAt' => $counted['counted_at'] ?? null,
            'liveCountries' => $counted === null ? null : RefreshToday::snapshot($counted)->countries,
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
            'site' => $site->load('ingestStatus', 'integrations'),
            'stripe' => $site->integrations->firstWhere('tool', IntegrationTool::Stripe),
            'stripeEvents' => $site->hookEvents()->where('tool', IntegrationTool::Stripe)->latest('id')->limit(5)->get(),
            'otherTeams' => $otherTeams,
            'setup' => new ShipperSetup($site),
            'lastDay' => $site->days()->latest('day')->first(),
        ]);
    }

    /**
     * Change the site's name and URL; a new URL reads the site's icon again.
     */
    public function update(SaveSiteRequest $request, Team $team, Site $site): RedirectResponse
    {
        $site->update($request->siteAttributes());

        if ($site->wasChanged('url')) {
            FetchSiteIcon::dispatch($site);
        }

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
