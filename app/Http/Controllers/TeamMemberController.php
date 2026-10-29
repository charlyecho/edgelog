<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    /**
     * Change the role of a member of the team. Only a viewer can be limited
     * to some sites and servers: any other role sees everything.
     */
    public function update(Request $request, Team $team, User $member): RedirectResponse
    {
        abort_unless($team->hasMember($member), 404);

        Gate::authorize('updateMember', [$team, $member]);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class)->only($request->user()->roleIn($team)->assignableRoles())],
        ]);

        $role = TeamRole::from($validated['role']);

        if ($role !== TeamRole::Owner && $team->isLastOwner($member)) {
            throw ValidationException::withMessages([
                'role' => __('A team always keeps one owner. Make someone else owner first.'),
            ]);
        }

        $team->members()->updateExistingPivot($member, ['role' => $role->value]);

        if ($role !== TeamRole::Viewer) {
            $member->membershipIn($team)->grant(false);
        }

        return back()->with('status', __(':name is now :role.', ['name' => $member->name, 'role' => $role->label()]));
    }

    /**
     * Remove a member from the team, or let a member leave it.
     */
    public function destroy(Request $request, Team $team, User $member): RedirectResponse
    {
        abort_unless($team->hasMember($member), 404);

        Gate::authorize('removeMember', [$team, $member]);

        if ($team->isLastOwner($member)) {
            throw ValidationException::withMessages([
                'member' => __('A team always keeps one owner. Make someone else owner first, or delete the team.'),
            ]);
        }

        $team->removeMember($member);

        if ($request->user()->is($member)) {
            return redirect()->route('dashboard')->with('status', __('You left :team.', ['team' => $team->name]));
        }

        return back()->with('status', __(':name was removed from the team.', ['name' => $member->name]));
    }
}
