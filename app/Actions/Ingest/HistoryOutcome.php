<?php

namespace App\Actions\Ingest;

/**
 * What became of a part of a past log file the endpoint took.
 */
final readonly class HistoryOutcome
{
    /**
     * @param  int  $shipped  lines at or after the first one shipped live, left to the live log
     * @param  int  $offset  where the next part of the file starts
     */
    public function __construct(
        public int $accepted,
        public int $rejected,
        public int $shipped,
        public int $offset,
    ) {}

    /**
     * @return array{ok: true, accepted: int, rejected: int, shipped: int, offset: int}
     */
    public function toArray(): array
    {
        return ['ok' => true, 'accepted' => $this->accepted, 'rejected' => $this->rejected, 'shipped' => $this->shipped, 'offset' => $this->offset];
    }
}
