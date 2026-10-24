<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
#[RouteKey('uuid')]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the members of the team.
     *
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the sites of the team.
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * Get the pending invitations of the team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the role the given user holds in the team, if any.
     */
    public function roleOf(User $user): ?TeamRole
    {
        $role = $this->members()->whereKey($user->getKey())->value('role');

        return $role === null ? null : TeamRole::from($role);
    }

    /**
     * Determine if the given user is a member of the team.
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    /**
     * Determine if the given user is the only owner of the team.
     */
    public function isLastOwner(User $user): bool
    {
        $ownerIds = $this->members()->wherePivot('role', TeamRole::Owner->value)->pluck('users.id');

        return $ownerIds->count() === 1 && $ownerIds->contains($user->getKey());
    }

    /**
     * Remove the given user from the team and move them to another of their teams.
     */
    public function removeMember(User $user): void
    {
        $this->members()->detach($user);

        if ($user->current_team_id === $this->getKey()) {
            $user->switchTeam($user->teams()->first());
        }
    }
}
