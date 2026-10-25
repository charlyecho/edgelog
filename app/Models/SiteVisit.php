<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One anonymous visit that read a page: where it came from, its pages in
 * order and how long it lasted. Kept analytics.journey_days.
 */
#[Guarded(['id'])]
#[WithoutTimestamps]
class SiteVisit extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'trail' => 'array',
            'not_found' => 'boolean',
            'posted' => 'boolean',
            'signed_in' => 'boolean',
            'returning' => 'boolean',
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
