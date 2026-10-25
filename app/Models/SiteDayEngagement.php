<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's visits of one source type or one device: how many, the pages
 * they read, those that read one page, and their lengths by bucket.
 */
#[Fillable(['dimension', 'name', 'visits', 'views', 'one_page', 'seconds'])]
#[WithoutTimestamps]
class SiteDayEngagement extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seconds' => 'array',
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
