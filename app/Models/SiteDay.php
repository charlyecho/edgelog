<?php

namespace App\Models;

use App\Analytics\DayFigures;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\EngineTier;
use App\Enums\TrafficGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;

/**
 * One day of a site's figures, in the time zone it was cut in.
 */
#[Guarded(['id'])]
class SiteDay extends Model
{
    /**
     * Requests from which a day with no stylesheet is taken as a log that leaves them out.
     */
    public const int BUSY_DAY_HITS = 200;

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
            'heartbeat' => 'array',
            'quality' => 'array',
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
     * @return HasMany<SiteDayUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(SiteDayUser::class);
    }

    /**
     * @return HasMany<SiteVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(SiteVisit::class);
    }

    /**
     * @return HasMany<SiteVisitorDay, $this>
     */
    public function visitors(): HasMany
    {
        return $this->hasMany(SiteVisitorDay::class);
    }

    /**
     * @return HasMany<SiteDayEngagement, $this>
     */
    public function engagements(): HasMany
    {
        return $this->hasMany(SiteDayEngagement::class);
    }

    /**
     * @return HasMany<SiteDayCrawler, $this>
     */
    public function crawlers(): HasMany
    {
        return $this->hasMany(SiteDayCrawler::class);
    }

    /**
     * @return HasOne<SiteDaySecurity, $this>
     */
    public function security(): HasOne
    {
        return $this->hasOne(SiteDaySecurity::class);
    }

    /**
     * @return HasMany<SiteDaySuspect, $this>
     */
    public function suspects(): HasMany
    {
        return $this->hasMany(SiteDaySuspect::class);
    }

    /**
     * @return HasMany<SiteDayAttack, $this>
     */
    public function attacks(): HasMany
    {
        return $this->hasMany(SiteDayAttack::class);
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
                'signed_in_users' => count($figures->users),
                'signed_in_readers' => $figures->signedInReaders,
                'stylesheets' => $figures->stylesheets,
                'heartbeat' => $figures->heartbeat,
                'quality' => $figures->quality,
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

            $siteDay->users()->delete();
            $users = array_map(
                fn (string $uid, array $user) => ['site_day_id' => $siteDay->id, 'uid' => $uid, 'views' => $user['views']],
                array_keys($figures->users),
                $figures->users,
            );

            foreach (array_chunk($users, 500) as $chunk) {
                SiteDayUser::query()->insert($chunk);
            }

            self::rememberUsers($site, $day, $figures);
            self::storeVisits($siteDay, $figures);
            self::rememberPages($site, $day, $figures);
            self::storeSecurity($siteDay, $figures);

            $siteDay->crawlers()->delete();

            foreach (array_chunk($figures->crawlers, 200) as $chunk) {
                SiteDayCrawler::query()->insert(array_map(fn (array $crawler) => [
                    'site_day_id' => $siteDay->id,
                    ...Arr::except($crawler, ['last_seen']),
                    'times' => json_encode($crawler['times']),
                    'last_seen_at' => CarbonImmutable::createFromTimestamp($crawler['last_seen']),
                ], $chunk));
            }

            return $siteDay;
        });
    }

    /**
     * Keep the day's engagement for good, and its visits and visitors' hashes
     * only when the day is recent enough for them to be kept at all.
     */
    private static function storeVisits(self $siteDay, DayFigures $figures): void
    {
        $siteDay->engagements()->delete();
        $siteDay->visits()->delete();
        $siteDay->visitors()->delete();

        foreach ($figures->engagement as $dimension => $rows) {
            foreach ($rows as $name => $row) {
                $siteDay->engagements()->create(['dimension' => $dimension, 'name' => (string) $name, ...$row]);
            }
        }

        $age = (int) CarbonImmutable::parse($siteDay->day->toDateString())->diffInDays(now($siteDay->timezone)->toDateString());

        if ($age < (int) config('analytics.journey_days')) {
            $rows = array_map(fn (array $journey) => [
                'site_day_id' => $siteDay->id,
                ...Arr::except($journey, ['visitor']),
                'started_at' => CarbonImmutable::createFromTimestamp($journey['started_at']),
                'trail' => json_encode($journey['trail']),
            ], $figures->journeys);

            foreach (array_chunk($rows, 500) as $chunk) {
                SiteVisit::query()->insert($chunk);
            }
        }

        if ($age < (int) config('analytics.visitors_kept_days')) {
            $rows = array_map(
                fn (string $visitor, array $seen) => ['site_day_id' => $siteDay->id, 'visitor' => $visitor, ...$seen],
                array_keys($figures->visitors),
                $figures->visitors,
            );

            foreach (array_chunk($rows, 500) as $chunk) {
                SiteVisitorDay::query()->insert($chunk);
            }
        }
    }

    /**
     * Keep the day's security figures for good, and its suspects' and
     * attackers' addresses only when the day is recent enough to keep them.
     */
    private static function storeSecurity(self $siteDay, DayFigures $figures): void
    {
        $siteDay->security()->delete();
        $siteDay->suspects()->delete();
        $siteDay->attacks()->delete();

        if ($figures->security === []) {
            return;
        }

        $siteDay->security()->create(Arr::only($figures->security, ['figures', 'hosts', 'exposures']));

        $age = (int) CarbonImmutable::parse($siteDay->day->toDateString())->diffInDays(now($siteDay->timezone)->toDateString());

        if ($age >= (int) config('analytics.security.kept_days')) {
            return;
        }

        foreach ($figures->security['suspects'] as $suspect) {
            $siteDay->suspects()->create([...$suspect, 'first_seen_at' => CarbonImmutable::createFromTimestamp($suspect['first_seen_at'])]);
        }

        foreach ($figures->security['episodes'] as $episode) {
            $siteDay->attacks()->create([
                'kind' => $episode['kind'],
                'address' => $episode['address'],
                'started_at' => CarbonImmutable::createFromTimestamp($episode['from']),
                'ended_at' => CarbonImmutable::createFromTimestamp($episode['to']),
                'requests' => $episode['requests'],
                'paths' => $episode['paths'],
                'answered' => $episode['answered'],
            ]);
        }
    }

    /**
     * Keep each page's first day read by a person and first day fetched by
     * Google, the earliest seen whatever order days are counted in.
     */
    private static function rememberPages(Site $site, string $day, DayFigures $figures): void
    {
        foreach (array_chunk($figures->pages, 500, true) as $chunk) {
            $known = $site->pages()->whereIn('path', array_map('strval', array_keys($chunk)))->get()->keyBy('path');
            $rows = [];

            foreach ($chunk as $path => $seen) {
                $page = $known[(string) $path] ?? null;
                $earliest = fn (?CarbonImmutable $kept, bool $now) => match (true) {
                    ! $now => $kept?->toDateString(),
                    $kept === null => $day,
                    default => min($kept->toDateString(), $day),
                };

                $rows[] = [
                    'site_id' => $site->id,
                    'path' => substr((string) $path, 0, 255),
                    'first_read_on' => $earliest($page?->first_read_on, $seen['read']),
                    'first_google_on' => $earliest($page?->first_google_on, $seen['google']),
                ];
            }

            SitePage::query()->upsert($rows, ['site_id', 'path'], ['first_read_on', 'first_google_on']);
        }
    }

    /**
     * Keep each user's first visit (the earliest seen, whatever order days
     * are counted in) and the last day they were seen.
     */
    private static function rememberUsers(Site $site, string $day, DayFigures $figures): void
    {
        $known = $site->users()->whereIn('uid', array_keys($figures->users))->get()->keyBy('uid');

        foreach ($figures->users as $uid => $user) {
            $siteUser = $known[$uid] ?? null;
            $first = [
                'first_seen_at' => $user['first_seen'],
                'first_source' => $user['source'],
                'first_referrer' => $user['referrer'],
                'first_landing' => $user['landing'],
            ];

            if ($siteUser === null) {
                $site->users()->create(['uid' => (string) $uid, 'last_seen_day' => $day, ...$first]);

                continue;
            }

            $siteUser->fill([
                'last_seen_day' => max($siteUser->last_seen_day->toDateString(), $day),
                ...($user['first_seen']->lessThan($siteUser->first_seen_at) ? $first : []),
            ])->save();
        }
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
            'users.signed_in' => $this->signed_in_users,
            'people.signed_in_readers' => $this->signed_in_readers,
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

        foreach ($this->counts->whereIn('kind', array_map(CountKind::from(...), CountKind::REVENUE)) as $row) {
            $core["{$row->kind->value}.{$row->name}"] = (int) $row->count;
        }

        return $core;
    }

    /**
     * Determine if the day's log seems to leave stylesheets out: a busy day
     * with none, so a page is told drawn only by the other files it asked for.
     */
    public function lacksStylesheets(): bool
    {
        return $this->hits >= self::BUSY_DAY_HITS && $this->stylesheets === 0;
    }

    /**
     * Determine if the logs did not cover the day from its start.
     */
    public function isPartial(): bool
    {
        return $this->partial_from !== null;
    }
}
