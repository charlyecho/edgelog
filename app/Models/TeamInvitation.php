<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['email', 'role', 'token_hash', 'expires_at', 'invited_by'])]
#[Hidden(['token_hash'])]
class TeamInvitation extends Model
{
    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    /**
     * How long an invitation link stays valid, in days.
     */
    public const int VALID_DAYS = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the team the invitation is for.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Hash a plain invitation token the way it is stored.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Determine if an email address holds a pending, non-expired invitation.
     */
    public static function isPendingFor(string $email): bool
    {
        return static::query()
            ->where('email', Str::lower($email))
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Find a pending, non-expired invitation from its plain token.
     */
    public static function findByToken(string $token): ?self
    {
        return static::query()
            ->where('token_hash', static::hashToken($token))
            ->where('expires_at', '>', now())
            ->first();
    }
}
