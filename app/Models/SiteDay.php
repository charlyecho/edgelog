<?php

namespace App\Models;

use App\Analytics\DayFigures;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\EngineTier;
use App\Enums\TrafficGroup;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day of a site's figures, in the time zone it was cut in.
 */
#[Guarded(['id'])]
class SiteDay extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'status' => DayStatus::class,
            'problems' => 'array',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'partial_from' => 'datetime',
            'collected_at' => 'datetime',
            'audited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<SiteDayGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(SiteDayGroup::class);
    }

    /**
     * @return HasMany<SiteDayCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(SiteDayCount::class);
    }

    /**
     * Only the days whose figures may be shown: counted twice, and both roads agree.
     *
     * @param  Builder<SiteDay>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('status', DayStatus::Verified);
    }

    /**
     * Replace the day's figures with these, under the given status.
     *
     * @param  list<string>  $problems
     */
    public static function store(Site $site, string $day, string $timezone, DayFigures $figures, DayStatus $status, array $problems): self
    {
        return $site->getConnection()->transaction(function () use ($site, $day, $timezone, $figures, $status, $problems) {
            $siteDay = $site->days()->updateOrCreate(['day' => $day], [
                'timezone' => $timezone,
                'status' => $status,
                'problems' => $problems === [] ? null : $problems,
                'parsed' => $figures->parsed,
                'unparsed' => $figures->unparsed,
                'hits' => $figures->hits,
                'other_hosts' => $figures->otherHosts,
                'unfiltered' => $figures->unfiltered,
                'cited_visits' => $figures->citedVisits,
                'first_seen' => $figures->firstSeen,
                'last_seen' => $figures->lastSeen,
                'partial_from' => $figures->partialFrom,
                'collected_at' => now(),
                'audited_at' => null,
            ]);

            $siteDay->groups()->delete();
            $siteDay->counts()->delete();

            $siteDay->groups()->createMany(array_map(
                fn (string $group) => ['group' => $group, ...$figures->groups[$group]->toArray()],
                array_keys($figures->groups),
            ));

            $rows = [];

            foreach ($figures->counts as $kind => $names) {
                foreach ($names as $name => $count) {
                    $rows[] = ['site_day_id' => $siteDay->id, 'kind' => $kind, 'name' => (string) $name, 'count' => $count];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                SiteDayCount::query()->insert($chunk);
            }

            return $siteDay;
        });
    }

    /**
     * The stored figures both roads count, keyed as DayFigures::core() keys them.
     *
     * @return array<string, int>
     */
    public function core(): array
    {
        $core = [
            'parsed' => $this->parsed,
            'hits' => $this->hits,
            'other_hosts' => $this->other_hosts,
            'cited_visits' => $this->cited_visits,
        ];

        $groups = $this->groups->keyBy('group');

        foreach (TrafficGroup::cases() as $group) {
            foreach (['requests', 'views', 'visits', 'readers', 'probes'] as $figure) {
                $core["{$group->value}.{$figure}"] = (int) ($groups[$group->value]->{$figure} ?? 0);
            }
        }

        $tiers = $this->counts->where('kind', CountKind::EngineTier->value)->pluck('count', 'name');

        foreach (EngineTier::cases() as $tier) {
            $core["tier.{$tier->value}"] = (int) ($tiers[$tier->value] ?? 0);
        }

        return $core;
    }

    /**
     * Determine if the logs did not cover the day from its start.
     */
    public function isPartial(): bool
    {
        return $this->partial_from !== null;
    }
}
