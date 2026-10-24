<?php

namespace App\Ingest;

/**
 * The number a shipper gives each batch, "<shipper id>:<seq>", so a batch
 * whose answer was lost on the way and is sent again is kept once.
 */
final readonly class BatchId
{
    /**
     * The header that carries it.
     */
    public const string HEADER = 'X-Edgelog-Batch';

    public function __construct(public string $shipper, public int $seq) {}

    /**
     * Read the header's value, or return null when it is missing or malformed.
     */
    public static function tryFrom(?string $value): ?self
    {
        if (preg_match('/^(?<shipper>[A-Za-z0-9_-]{1,64}):(?<seq>\d{1,18})$/', (string) $value, $parts) !== 1) {
            return null;
        }

        return new self($parts['shipper'], (int) $parts['seq']);
    }
}
