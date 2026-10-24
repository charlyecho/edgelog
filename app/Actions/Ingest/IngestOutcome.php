<?php

namespace App\Actions\Ingest;

/**
 * What became of a batch the endpoint took.
 */
final readonly class IngestOutcome
{
    public function __construct(
        public int $accepted,
        public int $rejected,
        public bool $duplicate = false,
    ) {}

    /**
     * The batch was kept before; nothing was written.
     */
    public static function duplicate(): self
    {
        return new self(0, 0, true);
    }

    /**
     * @return array{ok: true, accepted: int, rejected: int, duplicate: bool}
     */
    public function toArray(): array
    {
        return ['ok' => true, 'accepted' => $this->accepted, 'rejected' => $this->rejected, 'duplicate' => $this->duplicate];
    }
}
