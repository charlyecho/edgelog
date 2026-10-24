<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\Team;
use App\Models\User;

class SitePolicy
{
    /**
     * Determine whether the user can view the site.
     */
    public function view(User $user, Site $site): bool
    {
        return $user->belongsToTeam($site->team);
    }

    /**
     * Determine whether the user can add a site to the team.
     */
    public function create(User $user, Team $team): bool
    {
        return (bool) $user->roleIn($team)?->canManageSites();
    }

    /**
     * Determine whether the user can change the site and its token.
     */
    public function update(User $user, Site $site): bool
    {
        return $this->create($user, $site->team);
    }

    /**
     * Determine whether the user can delete the site.
     */
    public function delete(User $user, Site $site): bool
    {
        return $this->create($user, $site->team);
    }

    /**
     * Determine whether the user can move the site to the given team.
     *
     * The user must manage sites in both teams.
     */
    public function transfer(User $user, Site $site, Team $team): bool
    {
        return ! $site->team->is($team)
            && $this->update($user, $site)
            && $this->create($user, $team);
    }
}
