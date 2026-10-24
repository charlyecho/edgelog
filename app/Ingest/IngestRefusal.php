<?php

namespace App\Ingest;

use App\Enums\IngestRefusalReason;
use Exception;

/**
 * A batch the endpoint does not take, with the answer the shipper gets.
 */
final class IngestRefusal extends Exception
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(public readonly IngestRefusalReason $reason, public readonly array $headers = [])
    {
        parent::__construct($reason->words());
    }

    /**
     * Refuse with a Retry-After header, in seconds.
     */
    public static function retryAfter(IngestRefusalReason $reason, int $seconds): self
    {
        return new self($reason, ['Retry-After' => (string) max(1, $seconds)]);
    }
}
