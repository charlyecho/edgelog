<?php

namespace App\Analytics\Geo;

/**
 * The world map pages draw countries on: Natural Earth's 1:110m shapes
 * (public domain), projected with Equal Earth by tools/world-map.php, and
 * how a count is shaded on it.
 */
final class WorldMap
{
    /**
     * The most shades a map uses, light to dark.
     */
    public const int STEPS = 5;

    /**
     * @var ?array{width: int, height: int, paths: array<string, string>, points: array<string, array{float, float}>, dots: list<string>}
     */
    private static ?array $shapes = null;

    /**
     * Get the map's shapes: a path per country, a point per country (its
     * bubble's centre), and the countries too small for a shape, drawn as a dot.
     *
     * @return array{width: int, height: int, paths: array<string, string>, points: array<string, array{float, float}>, dots: list<string>}
     */
    public static function shapes(): array
    {
        return self::$shapes ??= json_decode(file_get_contents(resource_path('data/world-110m.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Cut the counts from 1 to the largest into at most STEPS ranges, each
     * wider than the last by the same factor: a few countries hold most visits.
     *
     * @return list<array{int, int}>
     */
    public static function ranges(int $largest): array
    {
        $ranges = [];
        $low = 1;

        for ($step = 1; $step <= self::STEPS && $low <= $largest; $step++) {
            $high = $step === self::STEPS ? $largest : min($largest, max($low, (int) round($largest ** ($step / self::STEPS))));
            $ranges[] = [$low, $high];
            $low = $high + 1;
        }

        return $ranges;
    }

    /**
     * Get the shade (1 to STEPS, 0 for none) of a count, the ranges spread over all the shades.
     *
     * @param  list<array{int, int}>  $ranges
     */
    public static function shade(int $count, array $ranges): int
    {
        foreach ($ranges as $index => [, $high]) {
            if ($count > 0 && $count <= $high) {
                return self::shadeOfRange($index, count($ranges));
            }
        }

        return 0;
    }

    /**
     * Get the shade of the given range when there are this many: the darkest is always the last.
     */
    public static function shadeOfRange(int $index, int $count): int
    {
        return $count <= 1 ? 3 : 1 + (int) round($index * (self::STEPS - 1) / ($count - 1));
    }
}
