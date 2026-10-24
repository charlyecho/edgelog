<?php

namespace App\Http\Controllers;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AcceptTeamInvitationController extends Controller
{
    /**
     * Show an invitation to the person who holds its link.
     */
    public function show(Request $request, string $token): View
    {
        $invitation = TeamInvitation::findByToken($token)?->load('team', 'inviter');

        if ($invitation !== null && $request->user() === null) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'emailMatches' => $invitation !== null && $this->emailMatches($request, $invitation),
        ]);
    }

    /**
     * Accept the invitation for the signed-in user.
     */
    public function store(Request $request, string $token, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse
    {
        $invitation = TeamInvitation::findByToken($token);

        abort_if($invitation === null, 404);
        abort_unless($this->emailMatches($request, $invitation), 403, __('This invitation was sent to another email address.'));

        $team = $acceptTeamInvitation->handle($invitation, $request->user());

        return redirect()->route('dashboard')->with('status', __('You joined :team.', ['team' => $team->name]));
    }

    /**
     * Determine if the invitation was sent to the signed-in user's email address.
     */
    private function emailMatches(Request $request, TeamInvitation $invitation): bool
    {
        return $request->user() !== null && Str::lower($request->user()->email) === $invitation->email;
    }
}
