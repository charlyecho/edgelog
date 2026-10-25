<?php

namespace App\Models;

use App\Enums\TrafficGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A crawler, assistant or bot on one day: its requests, the bytes sent, its
 * times (with their histogram, for the p90), its failed requests, its
 * distinct paths and when it was last seen.
 */
#[Fillable(['group', 'name', 'requests', 'bytes', 'time_ms', 'timed', 'times', 'errors', 'paths', 'last_seen_at'])]
#[WithoutTimestamps]
class SiteDayCrawler extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => TrafficGroup::class,
            'times' => 'array',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SiteDay, $this>
     */
    public function day(): BelongsTo
    {
        return $this->belongsTo(SiteDay::class, 'site_day_id');
    }
}
