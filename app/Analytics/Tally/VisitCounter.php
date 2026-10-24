<?php

namespace App\Analytics\Tally;

/**
 * Cuts one group's requests into visits: one address and user agent, no gap
 * over 30 minutes. For people, a page arriving from a new outside source
 * (one that differs from where the visit's first page came from) starts a
 * new visit too. A visit with a page view is a reader.
 */
final class VisitCounter
{
    public int $visits = 0;

    public int $readers = 0;

    /**
     * @var array<string, array{last: int, origin: ?string, id: int}>
     */
    private array $open = [];

    public function __construct(private readonly int $gapSeconds = 1800) {}

    /**
     * Count one request, in time order. Returns true when the request is
     * the first page view of its visit: the visit just became a reader.
     *
     * @param  ?string  $origin  where the page view came from, for a page view; null otherwise
     * @param  bool  $outside  the origin is an outside source (not this site, not direct)
     */
    public function add(string $key, int $time, ?string $origin = null, bool $outside = false): bool
    {
        $visit = $this->open[$key] ?? null;

        $isNew = $visit === null
            || $time - $visit['last'] > $this->gapSeconds
            || ($origin !== null && $outside && $visit['origin'] !== null && $visit['origin'] !== $origin);

        if ($isNew) {
            $this->visits++;
            $visit = ['last' => $time, 'origin' => null, 'id' => $this->visits];
        }

        $visit['last'] = max($visit['last'], $time);
        $firstPage = $origin !== null && $visit['origin'] === null;

        if ($firstPage) {
            $visit['origin'] = $origin;
            $this->readers++;
        }

        $this->open[$key] = $visit;

        return $firstPage;
    }

    /**
     * The number of the visit the key's last request belongs to (1 for the day's first visit).
     */
    public function visitOf(string $key): ?int
    {
        return $this->open[$key]['id'] ?? null;
    }
}
