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
     * @param  array<string, int|string>  $details  more fields for the answer's body
     */
    public function __construct(
        public readonly IngestRefusalReason $reason,
        public readonly array $headers = [],
        public readonly array $details = [],
    ) {
        parent::__construct($reason->words());
    }

    /**
     * Refuse with a Retry-After header, in seconds.
     */
    public static function retryAfter(IngestRefusalReason $reason, int $seconds): self
    {
        return new self($reason, ['Retry-After' => (string) max(1, $seconds)]);
    }

    /**
     * Refuse a part of a past file, saying where the server expects it to go on from.
     */
    public static function offset(int $expected): self
    {
        return new self(IngestRefusalReason::Offset, details: ['offset' => $expected]);
    }
}
