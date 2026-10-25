<?php

namespace App\Analytics\Crawlers;

/**
 * What the site's robots.txt says to each crawler, and whether it kept to
 * it: its paths are judged against today's rules, past days included.
 */
final readonly class RobotsVerdicts
{
    /**
     * @param  array<string, array<string, int>>  $paths  each crawler's fetches by path
     */
    public function __construct(public ?RobotsTxt $robots, private array $paths) {}

    /**
     * The crawler's verdict: the group it follows, the verdict in words and
     * its tone, its fetches the rules keep it out of, and the top such paths.
     *
     * @return array{group: ?string, label: string, tone: string, off_limits: int, paths: array<string, int>}
     */
    public function for(string $crawler): array
    {
        if ($this->robots === null) {
            return ['group' => null, 'label' => __('Not read'), 'tone' => 'muted', 'off_limits' => 0, 'paths' => []];
        }

        $offLimits = array_filter($this->paths[$crawler] ?? [], fn (string $path) => ! $this->robots->allows($crawler, $path), ARRAY_FILTER_USE_KEY);
        arsort($offLimits);
        $count = array_sum($offLimits);

        [$label, $tone] = match (true) {
            ! $this->robots->restricts($crawler) => [__('Allowed'), 'good'],
            ! $this->robots->allows($crawler, '/') => [$count > 0 ? __('Blocked, fetched anyway') : __('Blocked'), $count > 0 ? 'bad' : 'warn'],
            default => [$count > 0 ? __('Partly off-limits, fetched anyway') : __('Partly off-limits'), $count > 0 ? 'bad' : 'muted'],
        };

        return ['group' => $this->robots->groupFor($crawler), 'label' => $label, 'tone' => $tone, 'off_limits' => $count, 'paths' => array_slice($offLimits, 0, 3, true)];
    }
}
