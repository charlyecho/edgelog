<?php

namespace App\Http\Controllers;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TeamController extends Controller
{
    /**
     * Show the form to create a team.
     */
    public function create(): View
    {
        return view('teams.create');
    }

    /**
     * Create a team owned by the current user.
     */
    public function store(Request $request, CreateTeam $createTeam): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $team = $createTeam->handle($request->user(), $validated['name']);

        return redirect()->route('teams.show', $team)->with('status', __('Team created.'));
    }

    /**
     * Show the team's dashboard: its sites.
     */
    public function show(Team $team): View
    {
        Gate::authorize('view', $team);

        return view('teams.show', [
            'team' => $team,
            'sites' => $team->sites()->orderBy('name')->get(),
        ]);
    }

    /**
     * Show the team's settings: name, members and invitations.
     */
    public function edit(Request $request, Team $team): View
    {
        Gate::authorize('view', $team);

        $team->load(['members', 'invitations' => fn ($query) => $query->with('inviter')->latest()]);

        $role = $team->members->firstWhere('id', $request->user()->id)->membership->role;

        return view('teams.edit', [
            'team' => $team,
            'role' => $role,
            'ownerCount' => $team->members->where('membership.role', TeamRole::Owner)->count(),
        ]);
    }

    /**
     * Rename the team.
     */
    public function update(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $team->update($validated);

        return back()->with('status', __('Team renamed.'));
    }

    /**
     * Delete the team and move its members to another of their teams.
     */
    public function destroy(Team $team): RedirectResponse
    {
        Gate::authorize('delete', $team);

        DB::transaction(function () use ($team) {
            $memberIds = $team->members()->pluck('users.id');

            $team->delete();

            User::query()
                ->whereIn('id', $memberIds)
                ->whereNull('current_team_id')
                ->each(fn (User $member) => $member->switchTeam($member->teams()->first()));
        });

        return redirect()->route('dashboard')->with('status', __('Team deleted.'));
    }
}
