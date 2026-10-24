<?php

namespace App\Models;

use App\Enums\IntegrationTool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tool connected to a site, with the secret its webhooks are signed with.
 */
#[Fillable(['tool', 'secret', 'last_received_at', 'last_refused_at', 'last_refusal'])]
#[Hidden(['secret'])]
class SiteIntegration extends Model
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
            'secret' => 'encrypted',
            'last_received_at' => 'datetime',
            'last_refused_at' => 'datetime',
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
