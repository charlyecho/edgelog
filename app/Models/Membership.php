<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's place in a team: a role and, for a viewer, the sites and servers
 * they may see when they are limited to those granted one by one.
 */
class Membership extends Pivot
{
    /**
     * The table associated with the pivot model.
     *
     * @var string
     */
    protected $table = 'team_user';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'limited' => 'boolean',
        ];
    }

    /**
     * Get the sites granted to a limited viewer.
     *
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'membership_site', 'membership_id', 'site_id');
    }

    /**
     * Get the servers granted to a limited viewer.
     *
     * @return BelongsToMany<Server, $this>
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'membership_server', 'membership_id', 'server_id');
    }

    /**
     * Determine if the member sees only the sites and servers granted to them:
     * a viewer, limited. Owners and admins always see everything.
     */
    public function isLimited(): bool
    {
        return $this->role === TeamRole::Viewer && $this->limited;
    }

    /**
     * Determine if the member may see the site.
     */
    public function canSeeSite(Site $site): bool
    {
        return $site->team_id === $this->team_id
            && (! $this->isLimited() || $this->sites()->whereKey($site->getKey())->exists());
    }

    /**
     * Determine if the member may see the server.
     */
    public function canSeeServer(Server $server): bool
    {
        return $server->team_id === $this->team_id
            && (! $this->isLimited() || $this->servers()->whereKey($server->getKey())->exists());
    }

    /**
     * Give the member access to everything, or limit a viewer to the sites
     * and servers given, those of other teams left out.
     *
     * @param  list<int>  $siteIds
     * @param  list<int>  $serverIds
     */
    public function grant(bool $limited, array $siteIds = [], array $serverIds = []): void
    {
        $limited = $limited && $this->role === TeamRole::Viewer;

        $this->forceFill(['limited' => $limited])->save();

        $this->sites()->sync($limited ? Site::query()->where('team_id', $this->team_id)->whereKey($siteIds)->pluck('id') : []);
        $this->servers()->sync($limited ? Server::query()->where('team_id', $this->team_id)->whereKey($serverIds)->pluck('id') : []);
    }
}
