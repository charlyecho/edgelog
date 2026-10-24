<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteTeamMember
{
    /**
     * Invite an email address to the team and mail them the link.
     *
     * A newer invitation for the same address replaces the older one.
     * Only the hash of the token is stored, so the link is returned to be
     * shown once to the person who sent it.
     */
    public function handle(Team $team, User $inviter, string $email, TeamRole $role): string
    {
        $token = Str::random(48);
        $email = Str::lower($email);

        $invitation = DB::transaction(function () use ($team, $inviter, $email, $role, $token) {
            $team->invitations()->where('email', $email)->delete();

            return $team->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => TeamInvitation::hashToken($token),
                'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
                'invited_by' => $inviter->getKey(),
            ]);
        });

        $acceptUrl = route('invitations.show', $token);

        Mail::to($email)->send(new TeamInvitationMail($invitation, $acceptUrl));

        return $acceptUrl;
    }
}
