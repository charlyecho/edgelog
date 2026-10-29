<?php

namespace App\Http\Controllers;

use App\Actions\Teams\InviteTeamMember;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TeamInvitationController extends Controller
{
    /**
     * Invite someone to the team.
     */
    public function store(InviteTeamMemberRequest $request, Team $team, InviteTeamMember $inviteTeamMember): RedirectResponse
    {
        $acceptUrl = $inviteTeamMember->handle(
            $team,
            $request->user(),
            $request->validated('email'),
            $request->role(),
            $request->isLimited(),
            $request->siteIds(),
            $request->serverIds(),
        );

        return back()
            ->with('status', __('Invitation sent to :email.', ['email' => $request->validated('email')]))
            ->with('invitation_url', $acceptUrl);
    }

    /**
     * Cancel a pending invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $invitation->delete();

        return back()->with('status', __('Invitation cancelled.'));
    }
}
