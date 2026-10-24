<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in user of a site, by the opaque id the site's app logs: when
 * they were first seen, and where that visit came from.
 */
#[Fillable(['uid', 'first_seen_at', 'last_seen_day', 'first_source', 'first_referrer', 'first_landing'])]
class SiteUser extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_day' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
