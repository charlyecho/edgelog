<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view the team.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can rename the team.
     */
    public function update(User $user, Team $team): bool
    {
        return (bool) $user->roleIn($team)?->canManageMembers();
    }

    /**
     * Determine whether the user can delete the team.
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->roleIn($team) === TeamRole::Owner;
    }

    /**
     * Determine whether the user can invite members and cancel invitations.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return (bool) $user->roleIn($team)?->canManageMembers();
    }

    /**
     * Determine whether the user can change the role of the given member.
     *
     * Only owners may change another owner.
     */
    public function updateMember(User $user, Team $team, User $member): bool
    {
        $role = $user->roleIn($team);

        if (! $role?->canManageMembers()) {
            return false;
        }

        return $role === TeamRole::Owner || $member->roleIn($team) !== TeamRole::Owner;
    }

    /**
     * Determine whether the user can remove the given member from the team.
     *
     * Every member may leave a team on their own.
     */
    public function removeMember(User $user, Team $team, User $member): bool
    {
        if ($user->is($member)) {
            return $user->belongsToTeam($team);
        }

        return $this->updateMember($user, $team, $member);
    }
}
