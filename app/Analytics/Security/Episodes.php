<?php

namespace App\Analytics\Security;

/**
 * Episodes of too many requests from one address: every request that lies
 * in some window of `$seconds` holding at least `$threshold` requests, kept
 * in one episode while the gap between two such requests stays within the
 * window. Requests come in time order.
 */
final class Episodes
{
    /**
     * Each address's requests of the last window: [time, path, answered, counted].
     *
     * @var array<string, list<array{0: int, 1: string, 2: string, 3: bool}>>
     */
    private array $recent = [];

    /**
     * Each address's open episode.
     *
     * @var array<string, array{address: string, from: int, to: int, requests: int, paths: array<string, int>, answered: array<string, int>}>
     */
    private array $open = [];

    /**
     * @var list<array{address: string, from: int, to: int, requests: int, paths: array<string, int>, answered: array<string, int>}>
     */
    private array $closed = [];

    public function __construct(private readonly int $threshold, private readonly int $seconds) {}

    public function add(string $address, int $time, string $path, string $answered): void
    {
        $recent = $this->recent[$address] ?? [];
        $recent[] = [$time, $path, $answered, false];

        while ($recent !== [] && $recent[0][0] <= $time - $this->seconds) {
            array_shift($recent);
        }

        if (count($recent) >= $this->threshold) {
            $episode = $this->open[$address] ?? null;

            if ($episode !== null && $recent[0][0] - $episode['to'] > $this->seconds) {
                $this->closed[] = $episode;
                $episode = null;
            }

            $episode ??= ['address' => $address, 'from' => $recent[0][0], 'to' => $time, 'requests' => 0, 'paths' => [], 'answered' => []];

            foreach ($recent as &$request) {
                if (! $request[3]) {
                    $request[3] = true;
                    $episode['requests']++;
                    $episode['to'] = max($episode['to'], $request[0]);
                    $episode['paths'][$request[1]] = ($episode['paths'][$request[1]] ?? 0) + 1;
                    $episode['answered'][$request[2]] = ($episode['answered'][$request[2]] ?? 0) + 1;
                }
            }
            unset($request);

            $this->open[$address] = $episode;
        }

        $this->recent[$address] = $recent;
    }

    /**
     * Every episode, most requests first.
     *
     * @return list<array{address: string, from: int, to: int, requests: int, paths: array<string, int>, answered: array<string, int>}>
     */
    public function all(): array
    {
        $all = [...$this->closed, ...array_values($this->open)];
        usort($all, fn (array $a, array $b) => $b['requests'] <=> $a['requests']);

        return $all;
    }
}
