<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the teams the user is a member of.
     *
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('teams.name');
    }

    /**
     * Get the team the user is currently working in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the role the user holds in the given team, if any.
     */
    public function roleIn(Team $team): ?TeamRole
    {
        return $team->roleOf($this);
    }

    /**
     * Determine if the user is a member of the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $team->hasMember($this);
    }

    /**
     * Make the given team the user's current team, or clear it with null.
     */
    public function switchTeam(?Team $team): void
    {
        $this->forceFill(['current_team_id' => $team?->getKey()])->save();

        $this->setRelation('currentTeam', $team);
    }
}
