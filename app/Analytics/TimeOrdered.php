<?php

namespace App\Analytics;

use App\Ingest\LogHit;
use Generator;
use SplMinHeap;

/**
 * Puts hits back in time order as they stream by.
 *
 * nginx writes a line when its request ends, so a slow request lands after
 * faster ones that started later, and shipped batches can interleave. Each
 * hit is held back until one at least `$holdSeconds` newer was read, so
 * memory holds a minute of lines, never the day. Hits with the same time
 * keep the order they were read in.
 */
final class TimeOrdered
{
    /**
     * @param  iterable<LogHit>  $hits
     * @return Generator<int, LogHit>
     */
    public static function of(iterable $hits, int $holdSeconds = 60): Generator
    {
        $heap = new class extends SplMinHeap
        {
            /**
             * @param  array{0: int, 1: int, 2: LogHit}  $value1
             * @param  array{0: int, 1: int, 2: LogHit}  $value2
             */
            protected function compare(mixed $value1, mixed $value2): int
            {
                return [$value2[0], $value2[1]] <=> [$value1[0], $value1[1]];
            }
        };

        $sequence = 0;
        $newest = PHP_INT_MIN;

        foreach ($hits as $hit) {
            $time = $hit->when->getTimestamp();
            $newest = max($newest, $time);
            $heap->insert([$time, $sequence++, $hit]);

            while (! $heap->isEmpty() && $heap->top()[0] <= $newest - $holdSeconds) {
                yield $heap->extract()[2];
            }
        }

        while (! $heap->isEmpty()) {
            yield $heap->extract()[2];
        }
    }
}
