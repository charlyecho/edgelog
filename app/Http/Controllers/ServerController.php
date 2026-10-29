<?php

namespace App\Http\Controllers;

use App\Analytics\Server\ServerRange;
use App\Analytics\ServerAlerts;
use App\Analytics\SiteAlerts;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ServerController extends Controller
{
    /**
     * List the team's servers, each with its last minute, what is wrong with
     * it and its sites with theirs; then the sites on no server the user may see.
     */
    public function index(Request $request, Team $team): View
    {
        Gate::authorize('view', $team);

        $visibleSites = $team->sitesVisibleTo($request->user())->select('sites.id');
        $servers = $team->serversVisibleTo($request->user())
            ->with(['latestMinute', 'sites' => fn ($query) => $query->whereIn('sites.id', $visibleSites)->orderBy('name')])
            ->get()
            ->sortBy(fn (Server $server) => mb_strtolower($server->name()))
            ->values();

        $unattached = $team->sitesVisibleTo($request->user())
            ->where(fn ($query) => $query->whereNull('server_id')->orWhereNotIn('server_id', $servers->pluck('id')))
            ->orderBy('name')
            ->get();

        return view('servers.index', [
            'team' => $team,
            'servers' => $servers,
            'alerts' => ServerAlerts::for($servers),
            'siteAlerts' => SiteAlerts::for($servers->flatMap->sites->concat($unattached)),
            'unattached' => $unattached,
        ]);
    }

    /**
     * Show the server's figures over the range picked, and the sites it runs
     * that the user may see, with what is wrong with each.
     */
    public function show(Request $request, Team $team, Server $server): View
    {
        Gate::authorize('view', $server);

        $server->load(['sites' => fn ($query) => $query->whereIn('sites.id', $team->sitesVisibleTo($request->user())->select('sites.id'))->orderBy('name')]);

        return view('servers.show', [
            'team' => $team,
            'server' => $server,
            'siteAlerts' => SiteAlerts::for($server->sites),
            'range' => ServerRange::last($server, ServerRange::choose($request->query('range'))),
        ]);
    }

    /**
     * Give the server a name of its own, or none to show its host name again.
     */
    public function update(Request $request, Team $team, Server $server): RedirectResponse
    {
        Gate::authorize('update', $server);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $server->update(['name' => $validated['name'] ?? null]);

        return back()->with('status', __('Server saved.'));
    }

    /**
     * Forget the server and its figures; its sites keep theirs, and are tied
     * to a server again by their shipper's next sample.
     */
    public function destroy(Team $team, Server $server): RedirectResponse
    {
        Gate::authorize('delete', $server);

        $name = $server->name();
        $server->delete();

        return redirect()->route('teams.servers.index', $team)->with('status', __(':server was forgotten.', ['server' => $name]));
    }
}
