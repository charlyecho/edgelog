<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Models\Concerns\HasIcon;
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
    use HasFactory, HasIcon, HasUuids;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'icon_changed_at' => 'datetime',
        ];
    }

    /**
     * Get the address the team's icon is shown from, or null when it has none.
     */
    public function iconUrl(): ?string
    {
        return $this->icon_path === null ? null : route('teams.icon', [$this, 'v' => $this->icon_changed_at?->timestamp]);
    }

    /**
     * Keep an uploaded image as the team's icon.
     *
     * Returns false, keeping the previous icon, when the bytes are not an image of a known type.
     */
    public function storeIcon(string $contents): bool
    {
        return $this->putIcon($contents);
    }

    /**
     * Delete the team's icon: it is shown with its initial again.
     */
    public function forgetIcon(): void
    {
        $this->removeIcon();
    }

    protected function iconFolder(): string
    {
        return 'team-icons';
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
            ->withPivot('id', 'role', 'limited')
            ->withTimestamps();
    }

    /**
     * Get the sites of the team.
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->chaperone();
    }

    /**
     * Get the servers of the team's sites.
     *
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class)->chaperone();
    }

    /**
     * Get the team's sites the user may see: all of them, or those granted
     * to a limited viewer; none for someone outside the team.
     *
     * @return HasMany<Site, $this>
     */
    public function sitesVisibleTo(User $user): HasMany
    {
        return $this->visibleTo($this->sites(), $user, 'membership_site', 'site_id');
    }

    /**
     * Get the team's servers the user may see, the same way as its sites.
     *
     * @return HasMany<Server, $this>
     */
    public function serversVisibleTo(User $user): HasMany
    {
        return $this->visibleTo($this->servers(), $user, 'membership_server', 'server_id');
    }

    /**
     * @template TRelated of Model
     *
     * @param  HasMany<TRelated, $this>  $relation
     * @return HasMany<TRelated, $this>
     */
    private function visibleTo(HasMany $relation, User $user, string $grants, string $key): HasMany
    {
        $membership = $user->membershipIn($this);

        if ($membership === null) {
            return $relation->whereRaw('1 = 0');
        }

        return $relation->when($membership->isLimited(), fn ($query) => $query->whereIn(
            $relation->getRelated()->qualifyColumn('id'),
            fn ($grant) => $grant->select($key)->from($grants)->where('membership_id', $membership->getKey()),
        ));
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
        if ($this->relationLoaded('members')) {
            return $this->members->find($user->getKey())?->membership->role;
        }

        $role = $this->members()->whereKey($user->getKey())->value('role');

        return $role === null ? null : TeamRole::from($role);
    }

    /**
     * Determine if the given user is a member of the team; from its members
     * when a page loaded them, as the members table checks each row.
     */
    public function hasMember(User $user): bool
    {
        if ($this->relationLoaded('members')) {
            return $this->members->contains($user->getKey());
        }

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
