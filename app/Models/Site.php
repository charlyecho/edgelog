<?php

namespace App\Models;

use App\Analytics\ServedScripts;
use App\Enums\LogFormat;
use App\Models\Concerns\HasIcon;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'url', 'log_format', 'timezone', 'not_pages', 'own_paths'])]
#[Hidden(['token_hash'])]
#[RouteKey('uuid')]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasIcon, HasUuids;

    /**
     * @return HasOne<SiteRobots, $this>
     */
    public function robots(): HasOne
    {
        return $this->hasOne(SiteRobots::class);
    }

    /**
     * @return HasOne<SiteStack, $this>
     */
    public function stack(): HasOne
    {
        return $this->hasOne(SiteStack::class);
    }

    /**
     * @return HasOne<SiteSitemap, $this>
     */
    public function sitemap(): HasOne
    {
        return $this->hasOne(SiteSitemap::class);
    }

    /**
     * Get the server the site runs on, known once its shipper sent the server's
     * figures, or picked by hand (pinned: its shipper then never moves it).
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<SiteError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(SiteError::class);
    }

    /**
     * @return HasMany<SiteErrorOccurrence, $this>
     */
    public function errorOccurrences(): HasMany
    {
        return $this->hasMany(SiteErrorOccurrence::class);
    }

    /**
     * @return HasMany<SiteErrorStream, $this>
     */
    public function errorStreams(): HasMany
    {
        return $this->hasMany(SiteErrorStream::class);
    }

    /**
     * @return HasMany<SitePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SitePage::class);
    }

    /**
     * The prefix every site token starts with.
     */
    public const string TOKEN_PREFIX = 'edg_';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'log_format' => 'auto',
        'timezone' => 'UTC',
    ];

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
            'token_generated_at' => 'datetime',
            'icon_uploaded' => 'boolean',
            'icon_changed_at' => 'datetime',
            'log_format' => LogFormat::class,
            'not_pages' => 'array',
            'own_paths' => 'array',
            'served_scripts' => 'array',
            'server_pinned' => 'boolean',
        ];
    }

    /**
     * Get the team the site belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the days of figures collected for the site.
     *
     * @return HasMany<SiteDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(SiteDay::class);
    }

    /**
     * Get the tools connected to the site.
     *
     * @return HasMany<SiteIntegration, $this>
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(SiteIntegration::class);
    }

    /**
     * Get the verified webhook events of the site's tools.
     *
     * @return HasMany<HookEvent, $this>
     */
    public function hookEvents(): HasMany
    {
        return $this->hasMany(HookEvent::class);
    }

    /**
     * Get the site's signed-in users, by the id its app logs.
     *
     * @return HasMany<SiteUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(SiteUser::class);
    }

    /**
     * Get the money the site's tools reported.
     *
     * @return HasMany<Conversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /**
     * Get what the ingest endpoint last answered for the site.
     *
     * @return HasOne<IngestStatus, $this>
     */
    public function ingestStatus(): HasOne
    {
        return $this->hasOne(IngestStatus::class);
    }

    /**
     * The paths the site really has that scanners also ask for: those it set,
     * and every route of the scripts it was found to serve its pages through.
     *
     * @return list<string>
     */
    public function ownPathPatterns(): array
    {
        return array_values(array_unique([
            ...$this->own_paths ?? [],
            ...array_merge(...array_map(ServedScripts::paths(...), $this->served_scripts ?? [])),
        ]));
    }

    /**
     * Get the host name of the site's URL.
     */
    public function host(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }

    /**
     * Get the address the site's icon is shown from, or null when it has none.
     */
    public function iconUrl(Team $team): ?string
    {
        return $this->icon_path === null ? null : route('teams.sites.icon', [$team, $this, 'v' => $this->icon_changed_at?->timestamp]);
    }

    /**
     * Keep an image as the site's icon, read from the site or uploaded.
     *
     * Returns false, keeping the previous icon, when the bytes are not an image of a known type.
     */
    public function storeIcon(string $contents, bool $uploaded): bool
    {
        return $this->putIcon($contents, ['icon_uploaded' => $uploaded]);
    }

    /**
     * Delete the site's icon: it is shown with its initial again.
     */
    public function forgetIcon(): void
    {
        $this->removeIcon(['icon_uploaded' => false]);
    }

    protected function iconFolder(): string
    {
        return 'site-icons';
    }

    /**
     * Give the site a new token, without saving it.
     *
     * Only the hash is kept, so the plain token is returned to be shown once.
     */
    public function fillNewToken(): string
    {
        $token = self::TOKEN_PREFIX.Str::random(40);

        $this->forceFill([
            'token_hash' => self::hashToken($token),
            'token_generated_at' => now(),
        ]);

        return $token;
    }

    /**
     * Hash a plain site token the way it is stored.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Find the site a plain token belongs to.
     */
    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Reduce a URL to its origin: lowercase scheme and host, and the port if any.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = "https://{$url}";
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $origin = Str::lower($parts['scheme']).'://'.Str::lower($parts['host']);

        return isset($parts['port']) ? "{$origin}:{$parts['port']}" : $origin;
    }
}
