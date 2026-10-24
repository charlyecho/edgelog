<?php

namespace App\Models;

use App\Enums\IntegrationTool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified webhook event, kept as received (only the fields that count)
 * so the audit can recount the conversions from it by its own road.
 */
#[Fillable(['tool', 'event_id', 'type', 'livemode', 'occurred_at', 'payload', 'processed_at'])]
class HookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool' => IntegrationTool::class,
            'livemode' => 'boolean',
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'processed_at' => 'datetime',
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
