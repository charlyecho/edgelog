<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Http\Requests\SaveMemberAccessRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TeamMemberAccessController extends Controller
{
    /**
     * Show what a viewer may see: everything, or the sites and servers ticked.
     */
    public function edit(Team $team, User $member): View
    {
        $membership = $member->membershipIn($team);

        abort_if($membership === null, 404);

        Gate::authorize('updateMember', [$team, $member]);

        abort_unless($membership->role === TeamRole::Viewer, 404);

        return view('teams.member-access', [
            'team' => $team,
            'member' => $member,
            'membership' => $membership,
            'sites' => $team->sites()->with('server')->orderBy('name')->get(),
            'servers' => $team->servers()->with('sites')->get()->sortBy(fn ($server) => mb_strtolower($server->name()))->values(),
            'grantedSites' => $membership->sites()->pluck('sites.uuid')->all(),
            'grantedServers' => $membership->servers()->pluck('servers.uuid')->all(),
        ]);
    }

    /**
     * Give a viewer access to everything, or only to the sites and servers ticked.
     */
    public function update(SaveMemberAccessRequest $request, Team $team, User $member): RedirectResponse
    {
        $membership = $member->membershipIn($team);

        abort_if($membership === null, 404);
        abort_unless($membership->role === TeamRole::Viewer, 404);

        $membership->grant($request->isLimited(), $request->siteIds(), $request->serverIds());

        return redirect()
            ->route('teams.edit', $team)
            ->with('status', $request->isLimited()
                ? __(':name now sees :sites and :servers.', [
                    'name' => $member->name,
                    'sites' => trans_choice(':count site|:count sites', count($request->siteIds())),
                    'servers' => trans_choice(':count server|:count servers', count($request->serverIds())),
                ])
                : __(':name now sees every site and server of the team.', ['name' => $member->name]));
    }
}
