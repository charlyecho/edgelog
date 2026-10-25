<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page of a site, and the first days it was read by a person and
 * fetched by Google: the earlier is taken as its publish date.
 */
#[Fillable(['path', 'first_read_on', 'first_google_on'])]
#[WithoutTimestamps]
class SitePage extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_read_on' => 'immutable_date',
            'first_google_on' => 'immutable_date',
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
     * The first day the page was seen at all.
     */
    public function publishedOn(): ?CarbonImmutable
    {
        return collect([$this->first_read_on, $this->first_google_on])->filter()->min();
    }
}
