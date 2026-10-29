<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A machine that runs one or more sites of a team, made the first time one
 * of their shippers sends its figures (edgelog-ship --metrics): every site
 * whose shipper sends the same machine id shares it, so its figures are
 * kept once. A site can also be put on a server by hand, made then or not.
 */
#[Fillable(['name', 'machine_id', 'hostname'])]
#[RouteKey('uuid')]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sample_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the team the server belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the sites the server runs.
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<ServerMetricMinute, $this>
     */
    public function metricMinutes(): HasMany
    {
        return $this->hasMany(ServerMetricMinute::class);
    }

    /**
     * @return HasMany<ServerMetricHour, $this>
     */
    public function metricHours(): HasMany
    {
        return $this->hasMany(ServerMetricHour::class);
    }

    /**
     * Get the last minute the server sent.
     *
     * @return HasOne<ServerMetricMinute, $this>
     */
    public function latestMinute(): HasOne
    {
        return $this->hasOne(ServerMetricMinute::class)->latestOfMany('minute');
    }

    /**
     * The name the server is shown with: the one given by hand, else its host
     * name, else the host of its first site while no shipper sent one.
     */
    public function name(): string
    {
        return $this->name ?? $this->hostname ?? $this->sites->sortBy('name')->first()?->host() ?? __('Server');
    }

    /**
     * The time zone its figures are drawn in: that of its sites when they
     * share one, UTC otherwise.
     */
    public function timezone(): string
    {
        $timezones = $this->sites->pluck('timezone')->unique();

        return $timezones->count() === 1 ? $timezones->first() : 'UTC';
    }
}
