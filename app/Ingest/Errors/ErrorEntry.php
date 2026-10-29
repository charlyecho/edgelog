<?php

namespace App\Ingest\Errors;

use Carbon\CarbonImmutable;

/**
 * One error read from an application log: what was thrown and where, and the
 * request it answered when the log says it.
 */
final readonly class ErrorEntry
{
    public function __construct(
        public CarbonImmutable $at,
        public string $level,
        public ?string $class,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public string $stack = '',
        public ?string $method = null,
        public ?string $path = null,
        public ?string $host = null,
        public ?string $referrer = null,
        public ?string $address = null,
        public ?string $userAgent = null,
        public ?int $status = null,
    ) {}

    /**
     * Determine if the log itself named the request: its path at least.
     */
    public function knowsItsRequest(): bool
    {
        return $this->path !== null;
    }
}
