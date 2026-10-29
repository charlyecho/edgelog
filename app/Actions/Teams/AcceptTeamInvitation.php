<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptTeamInvitation
{
    /**
     * Add the user to the invitation's team, with the sites and servers it
     * limits a viewer to, and make it their current team.
     *
     * Someone who is already a member keeps their current role and access.
     */
    public function handle(TeamInvitation $invitation, User $user): Team
    {
        return DB::transaction(function () use ($invitation, $user) {
            $team = $invitation->team;

            if (! $team->hasMember($user)) {
                $team->members()->attach($user, ['role' => $invitation->role->value]);
                $user->membershipIn($team)->grant($invitation->limited, $invitation->site_ids ?? [], $invitation->server_ids ?? []);
            }

            $invitation->delete();

            $user->switchTeam($team);

            return $team;
        });
    }
}
