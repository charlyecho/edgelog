<?php

namespace App\Http\Controllers;

use App\Analytics\Server\ServerRange;
use App\Ingest\ShipperSetup;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteServerController extends Controller
{
    /**
     * The site's server as edgelog-ship --metrics reports it: its last
     * minute, its disks, and its processors, memory, load, network and disks
     * over the range picked, with the other sites it runs; nothing of a
     * server the user is not granted.
     */
    public function show(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $server = $site->server !== null && $request->user()->can('view', $site->server) ? $site->server : null;

        return view('sites.site.server', [
            'team' => $team,
            'site' => $site,
            'server' => $server,
            'hiddenServer' => $site->server !== null && $server === null,
            'range' => ServerRange::last($server, ServerRange::choose($request->query('range'))),
            'neighbours' => $server?->sites()->whereKeyNot($site->id)->whereIn('sites.id', $team->sitesVisibleTo($request->user())->select('sites.id'))->orderBy('name')->get() ?? collect(),
            'setup' => new ShipperSetup($site),
        ]);
    }

    /**
     * Put the site on a server by hand, of the team or made for it: its
     * shipper's samples go there from then on, whatever machine they name.
     * Back to automatic, the next sample ties it to its machine's server.
     */
    public function update(Request $request, Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate([
            'server' => ['required', 'string', Rule::in(['auto', 'new', ...$team->servers()->pluck('uuid')])],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $server = match ($validated['server']) {
            'auto' => $site->server,
            'new' => $team->servers()->create(['name' => $validated['name'] ?? null]),
            default => $team->servers()->where('uuid', $validated['server'])->sole(),
        };

        $site->server()->associate($server)->forceFill(['server_pinned' => $validated['server'] !== 'auto'])->save();

        return back()->with('status', $validated['server'] === 'auto'
            ? __('The site\'s server is found from its shipper again.')
            : __(':site now runs on :server.', ['site' => $site->name, 'server' => $server->load('sites')->name()]));
    }
}
