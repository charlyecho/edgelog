<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader seen on one day, by a keyed hash only, with their visits that
 * day and where the first came from. Kept analytics.returning_days.
 */
#[Fillable(['visitor', 'visits', 'source'])]
#[WithoutTimestamps]
class SiteVisitorDay extends Model
{
    /**
     * @return BelongsTo<SiteDay, $this>
     */
    public function day(): BelongsTo
    {
        return $this->belongsTo(SiteDay::class, 'site_day_id');
    }
}
