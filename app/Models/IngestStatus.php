<?php

namespace App\Models;

use App\Enums\IngestRefusalReason;
use App\Ingest\BatchId;
use App\Ingest\UtcDay;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * What the endpoint last answered for a site, for the connection test.
 *
 * Never holds an address, a user agent, a token or a log line.
 */
#[Table('site_ingest_statuses')]
#[Guarded(['id', 'site_id'])]
class IngestStatus extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_batch_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'today' => 'date:Y-m-d',
            'last_refusal_at' => 'datetime',
            'last_refusal_reason' => IngestRefusalReason::class,
        ];
    }

    /**
     * Get the site the status is about.
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Note an accepted batch.
     */
    public static function recordBatch(Site $site, BatchId $batch, int $accepted, int $rejected): void
    {
        $status = $site->ingestStatus()->firstOrNew();
        $today = UtcDay::today();

        $status->fill([
            'last_batch_at' => now(),
            'last_batch_lines' => $accepted,
            'last_batch_rejected' => $rejected,
            'last_batch_shipper' => $batch->shipper,
            'last_contact_at' => now(),
            'today_lines' => ($status->today?->toDateString() === $today ? $status->today_lines : 0) + $accepted,
            'today' => $today,
        ])->save();
    }

    /**
     * Note a refusal, at most one a second per site, so a stream of bad
     * posts cannot keep the database busy.
     */
    public static function recordRefusal(Site $site, IngestRefusalReason $reason): void
    {
        if (! Cache::add("ingest-refusal:{$site->id}", true, 1)) {
            return;
        }

        $site->ingestStatus()->firstOrNew()->fill([
            'last_refusal_at' => now(),
            'last_refusal_status' => $reason->status(),
            'last_refusal_reason' => $reason,
        ])->save();
    }

    /**
     * Determine if the last refusal came after the last accepted batch.
     */
    public function isRefusing(): bool
    {
        return $this->last_refusal_at !== null
            && ($this->last_batch_at === null || $this->last_refusal_at->greaterThan($this->last_batch_at));
    }
}
