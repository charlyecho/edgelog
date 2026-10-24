<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a team owned by the given user and make it their current team.
     */
    public function handle(User $owner, string $name): Team
    {
        return DB::transaction(function () use ($owner, $name) {
            $team = Team::create(['name' => $name]);

            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

            $owner->switchTeam($team);

            return $team;
        });
    }
}
