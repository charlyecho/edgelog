<?php

namespace App\Analytics;

/**
 * What a period's visit histograms say: the typical (median) visit, the
 * buckets the charts draw, and a length in words.
 */
final class VisitStats
{
    /**
     * The time on site buckets the chart draws: [label, from second, up to second].
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function lengthBuckets(): array
    {
        return [
            [__('under 10 s'), 0, 10],
            [__('10–30 s'), 10, 30],
            [__('30 s–1 min'), 30, 60],
            [__('1–3 min'), 60, 180],
            [__('3–10 min'), 180, 600],
            [__('over 10 min'), 600, PHP_INT_MAX],
        ];
    }

    /**
     * Visits by pages read, as the chart draws them: 1 to 4, then 5 or more.
     *
     * @param  array<string, int>  $pages
     * @return array<string, int>
     */
    public static function pagesChart(array $pages): array
    {
        $chart = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, __('5 or more') => 0];

        foreach ($pages as $read => $visits) {
            $chart[(int) $read >= 5 ? __('5 or more') : (string) (int) $read] += $visits;
        }

        return $chart;
    }

    /**
     * Visits by length, as the chart draws them.
     *
     * @param  array<string, int>  $seconds
     * @return array<string, int>
     */
    public static function lengthChart(array $seconds): array
    {
        $chart = [];

        foreach (self::lengthBuckets() as [$label, $from, $to]) {
            $chart[$label] = array_sum(array_filter($seconds, fn (string $bucket) => (int) $bucket >= $from && (int) $bucket < $to, ARRAY_FILTER_USE_KEY));
        }

        return $chart;
    }

    /**
     * The median of a histogram (value => visits), or null when it is empty.
     *
     * @param  array<string, int>  $histogram
     */
    public static function median(array $histogram): ?int
    {
        $total = array_sum($histogram);

        if ($total === 0) {
            return null;
        }

        ksort($histogram, SORT_NUMERIC);
        $middle = intdiv($total - 1, 2);

        foreach ($histogram as $value => $visits) {
            if ($middle < $visits) {
                return (int) $value;
            }

            $middle -= $visits;
        }

        return null;
    }

    /**
     * A length in words: "under 1 s", "48 s", "1 min 17 s", "2 h 5 min".
     */
    public static function words(?int $seconds): string
    {
        return match (true) {
            $seconds === null => '',
            $seconds < 1 => __('under 1 s'),
            $seconds < 60 => __(':s s', ['s' => $seconds]),
            $seconds < 3600 => $seconds % 60 === 0 ? __(':m min', ['m' => intdiv($seconds, 60)]) : __(':m min :s s', ['m' => intdiv($seconds, 60), 's' => $seconds % 60]),
            default => __(':h h :m min', ['h' => intdiv($seconds, 3600), 'm' => intdiv($seconds % 3600, 60)]),
        };
    }
}
