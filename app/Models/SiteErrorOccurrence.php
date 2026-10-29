<?php

namespace App\Models;

use App\Enums\ErrorLogKind;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time an error was logged, with the request it answered: as the log
 * wrote it (FROM_LOG), found in the access log at the same second
 * (FROM_ACCESS), or unknown. Kept `errors.occurrence_days` days.
 */
#[Guarded(['id', 'site_error_id'])]
#[WithoutTimestamps]
class SiteErrorOccurrence extends Model
{
    /**
     * The error log itself named the request.
     */
    public const string FROM_LOG = 'log';

    /**
     * The request was found in the access log.
     */
    public const string FROM_ACCESS = 'access';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ErrorLogKind::class,
            'at' => 'immutable_datetime',
            'looked_up_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SiteError, $this>
     */
    public function error(): BelongsTo
    {
        return $this->belongsTo(SiteError::class, 'site_error_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
