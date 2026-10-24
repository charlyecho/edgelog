<?php

namespace App\Ingest;

use App\Enums\LogFormat;
use Carbon\CarbonImmutable;

/**
 * One request read from an access log line: the unit every count is built from.
 */
final readonly class LogHit
{
    public function __construct(
        public CarbonImmutable $when,
        public string $address,
        public string $host,
        public string $method,
        public string $url,
        public int $status,
        public string $referrer,
        public string $userAgent,
        public LogFormat $format,
        public string $country = '',
        public ?float $requestTime = null,
        public ?int $size = null,
        public string $requestId = '',
        public ?string $rscPrefetch = null,
        public ?int $requestLength = null,
    ) {}

    /**
     * Get the path of the requested URL, without its query string.
     */
    public function path(): string
    {
        $query = strpos($this->url, '?');

        return $query === false ? $this->url : substr($this->url, 0, $query);
    }
}
