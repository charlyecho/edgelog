<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a server said about itself in one minute (edgelog-ship
 * --metrics): its processors, memory, disks and network. Rates are per
 * second since the sample before, so the first one after a start has none.
 */
#[Guarded(['id', 'server_id'])]
#[WithoutTimestamps]
class ServerMetricMinute extends Model
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
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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
