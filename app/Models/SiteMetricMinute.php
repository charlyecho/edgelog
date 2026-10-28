<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the site's server said about itself in one minute (edgelog-ship
 * --metrics): its processors, memory, disks and network. Rates are per
 * second since the sample before, so the first one after a start has none.
 */
#[Guarded(['id', 'site_id'])]
#[WithoutTimestamps]
class SiteMetricMinute extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minute' => 'immutable_datetime',
            'disks' => 'array',
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
     * The memory in use, in bytes: all of it but what could be had at once.
     */
    public function memoryUsed(): int
    {
        return max(0, $this->memory_total - $this->memory_available);
    }

    /**
     * The swap in use, in bytes.
     */
    public function swapUsed(): int
    {
        return max(0, $this->swap_total - $this->swap_free);
    }
}
