<?php

namespace App\Models;

use App\Enums\ConversionAction;
use App\Enums\IntegrationTool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money in or out, as a tool reported it: an amount in the currency's minor
 * unit (cents), negative for a refund. Kept once per tool, reference and action.
 */
#[Fillable(['tool', 'external_ref', 'action', 'amount', 'currency', 'livemode', 'occurred_at', 'uid'])]
class Conversion extends Model
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
            'action' => ConversionAction::class,
            'amount' => 'integer',
            'livemode' => 'boolean',
            'occurred_at' => 'datetime',
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
