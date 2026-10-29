<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Determine whether the user can view the server: a member of its team,
     * granted the server when they are a limited viewer. Seeing one of its
     * sites is not enough.
     */
    public function view(User $user, Server $server): bool
    {
        return (bool) $user->membershipIn($server->team)?->canSeeServer($server);
    }

    /**
     * Determine whether the user can rename the server.
     */
    public function update(User $user, Server $server): bool
    {
        return (bool) $user->roleIn($server->team)?->canManageSites();
    }

    /**
     * Determine whether the user can forget the server and its figures.
     */
    public function delete(User $user, Server $server): bool
    {
        return (bool) $user->roleIn($server->team)?->canManageSites();
    }
}
