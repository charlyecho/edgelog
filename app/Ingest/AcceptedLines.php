<?php

namespace App\Ingest;

/**
 * The lines of a batch that will be kept, and how many were refused.
 */
final readonly class AcceptedLines
{
    public function __construct(
        public string $data,
        public int $accepted,
        public int $rejected,
    ) {}

    /**
     * The size of the kept lines, in bytes.
     */
    public function bytes(): int
    {
        return strlen($this->data);
    }
}
