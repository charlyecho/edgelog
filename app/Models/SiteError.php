<?php

namespace App\Models;

use App\Enums\ErrorLogKind;
use App\Enums\ErrorStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One error of the site's application logs: every occurrence with the same
 * fingerprint (class, where it was thrown, message), with the last one's
 * message and stack trace. Open until someone resolves or ignores it; a
 * resolved error seen again is open again, a regression.
 */
#[Guarded(['id', 'site_id'])]
#[WithoutTimestamps]
class SiteError extends Model
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
            'status' => ErrorStatus::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'regressed_at' => 'immutable_datetime',
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
     * @return HasMany<SiteErrorOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(SiteErrorOccurrence::class);
    }

    /**
     * The name to show: the class thrown, or the level when the log named none.
     */
    public function title(): string
    {
        return $this->class ?? ucfirst($this->level);
    }

    /**
     * Where it was thrown, "file:line", or null when the log did not say.
     */
    public function place(): ?string
    {
        return $this->file === null ? null : $this->file.($this->line === null ? '' : ':'.$this->line);
    }
}
