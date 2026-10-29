<?php

namespace App\Models;

use App\Enums\ErrorLogKind;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One error log as one shipper sends it: the last batch kept, so a batch
 * sent again is read once, and the entry still being written (the tail).
 */
#[Guarded(['id', 'site_id'])]
#[WithoutTimestamps]
class SiteErrorStream extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ErrorLogKind::class,
            'tail_at' => 'immutable_datetime',
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
