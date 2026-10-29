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
     * A viewer may be limited to the sites and servers given, which they get
     * as they accept. A newer invitation for the same address replaces the
     * older one.
     * Only the hash of the token is stored, so the link is returned to be
     * shown once to the person who sent it.
     *
     * @param  list<int>  $siteIds
     * @param  list<int>  $serverIds
     */
    public function handle(Team $team, User $inviter, string $email, TeamRole $role, bool $limited = false, array $siteIds = [], array $serverIds = []): string
    {
        $token = Str::random(48);
        $email = Str::lower($email);
        $limited = $limited && $role === TeamRole::Viewer;

        $invitation = DB::transaction(function () use ($team, $inviter, $email, $role, $limited, $siteIds, $serverIds, $token) {
            $team->invitations()->where('email', $email)->delete();

            return $team->invitations()->create([
                'email' => $email,
                'role' => $role,
                'limited' => $limited,
                'site_ids' => $limited ? $siteIds : null,
                'server_ids' => $limited ? $serverIds : null,
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
