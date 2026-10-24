<?php

namespace App\Analytics\Audit;

/**
 * Where the count and the recount of a day disagree.
 */
final class Comparison
{
    /**
     * @param  array<string, int>  $counted
     * @param  array<string, int>  $recounted
     * @return list<string> one plain line per figure that differs
     */
    public static function differences(array $counted, array $recounted): array
    {
        $differences = [];

        foreach (array_unique([...array_keys($counted), ...array_keys($recounted)]) as $figure) {
            $a = $counted[$figure] ?? 0;
            $b = $recounted[$figure] ?? 0;

            if ($a !== $b) {
                $differences[] = __('The recount found :recounted for :figure, the count :counted.', [
                    'figure' => $figure,
                    'counted' => $a,
                    'recounted' => $b,
                ]);
            }
        }

        return $differences;
    }
}
