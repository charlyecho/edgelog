<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What serves a site, as last read from outside: each host it answers on,
 * what runs it, the versions shown and how each stands with its branch.
 */
#[Fillable(['hosts', 'checked_at'])]
#[WithoutTimestamps]
class SiteStack extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hosts' => 'array',
            'checked_at' => 'immutable_datetime',
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
     * Every product of every host that needs looking at, worst first:
     * a branch no longer supported, then a newer release, then a version not shown.
     *
     * @return list<array{host: string, product: string, version: ?string, status: string, cycle: ?string, latest: ?string, eol: ?string, newest: ?string}>
     */
    public function attention(): array
    {
        $order = ['eol' => 0, 'update' => 1, 'check_version' => 2];
        $items = [];

        foreach ($this->hosts as $host) {
            foreach ($host['products'] as $product => $found) {
                if (isset($order[$found['status']])) {
                    $items[] = ['host' => $host['host'], 'product' => $product, ...$found];
                }
            }
        }

        usort($items, fn (array $a, array $b) => $order[$a['status']] <=> $order[$b['status']]);

        return $items;
    }
}
