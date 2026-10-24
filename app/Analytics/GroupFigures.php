<?php

namespace App\Analytics;

/**
 * One group's figures for a day.
 */
final class GroupFigures
{
    public function __construct(
        public int $requests = 0,
        public int $views = 0,
        public int $visits = 0,
        public int $readers = 0,
        public int $probes = 0,
    ) {}

    /**
     * @return array{requests: int, views: int, visits: int, readers: int, probes: int}
     */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
            'views' => $this->views,
            'visits' => $this->visits,
            'readers' => $this->readers,
            'probes' => $this->probes,
        ];
    }
}
