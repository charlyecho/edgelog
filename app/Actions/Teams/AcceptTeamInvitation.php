<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptTeamInvitation
{
    /**
     * Add the user to the invitation's team and make it their current team.
     *
     * Someone who is already a member keeps their current role.
     */
    public function handle(TeamInvitation $invitation, User $user): Team
    {
        return DB::transaction(function () use ($invitation, $user) {
            $team = $invitation->team;

            if (! $team->hasMember($user)) {
                $team->members()->attach($user, ['role' => $invitation->role->value]);
            }

            $invitation->delete();

            $user->switchTeam($team);

            return $team;
        });
    }
}
