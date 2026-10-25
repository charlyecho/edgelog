<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address and browser that probed the site, attacked it or posed as a browser, on one day.
 */
#[Fillable(['address', 'country', 'user_agent', 'first_seen_at', 'requests', 'probes', 'attempts', 'fake_requests', 'categories', 'answered', 'paths', 'signs'])]
#[WithoutTimestamps]
class SiteDaySuspect extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'categories' => 'array',
            'answered' => 'array',
            'paths' => 'array',
            'signs' => 'array',
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
