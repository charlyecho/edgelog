<?php

/**
 * Build the world map the pages draw: resources/data/world-110m.json.
 *
 *     php tools/world-map.php ne_110m_admin_0_countries.geojson
 *
 * The input is Natural Earth's 1:110m "Admin 0 - Countries" (public domain,
 * naturalearthdata.com; a GeoJSON copy is in github.com/nvkelso/natural-earth-vector,
 * geojson/ne_110m_admin_0_countries.geojson), downloaded once by hand. The output
 * holds SVG path data, so a page draws the map with no request and no outside code.
 *
 * Each country is keyed by its ISO 3166-1 alpha-2 code (ISO_A2, else ISO_A2_EH:
 * France and Norway carry -99 in ISO_A2); a shape with neither is left out, and
 * so is Antarctica. Longitudes and latitudes are projected with Equal Earth
 * (Šavrič, Patterson and Jenny, 2018), so areas are true; each ring is simplified
 * (Douglas-Peucker) and tiny islands dropped. Each country also gets a point, the
 * centre of its largest ring, for the "active now" bubbles; countries too small
 * for these shapes get theirs from SMALL_COUNTRIES and are drawn as a dot.
 *
 * Run it again only to change the projection or the detail, and commit the output.
 */
const WIDTH = 1000.0;
const TOLERANCE = 0.55;
const MIN_AREA = 2.0;
const TOP_LATITUDE = 84.0;
const SKIPPED = ['AQ'];

/** Countries the 1:110m shapes do not hold, as [latitude, longitude]. */
const SMALL_COUNTRIES = [
    'AD' => [42.55, 1.58], 'AG' => [17.07, -61.8], 'AW' => [12.52, -69.97], 'BB' => [13.19, -59.54],
    'BH' => [26.07, 50.55], 'BM' => [32.31, -64.75], 'CV' => [15.12, -23.6], 'CW' => [12.17, -68.98],
    'DM' => [15.41, -61.37], 'FM' => [6.92, 158.18], 'FO' => [62.0, -6.79], 'GD' => [12.12, -61.67],
    'GG' => [49.45, -2.58], 'GI' => [36.14, -5.35], 'GP' => [16.25, -61.55], 'HK' => [22.32, 114.17],
    'IM' => [54.24, -4.55], 'JE' => [49.21, -2.13], 'KI' => [1.87, -157.4], 'KM' => [-11.65, 43.33],
    'KN' => [17.34, -62.78], 'KY' => [19.31, -81.25], 'LC' => [13.91, -60.98], 'LI' => [47.17, 9.56],
    'LU' => [49.82, 6.13], 'MC' => [43.74, 7.42], 'MH' => [7.13, 171.18], 'MO' => [22.2, 113.54],
    'MQ' => [14.64, -61.02], 'MT' => [35.94, 14.38], 'MU' => [-20.35, 57.55], 'MV' => [3.2, 73.22],
    'PF' => [-17.68, -149.41], 'PW' => [7.51, 134.58], 'RE' => [-21.12, 55.54], 'SC' => [-4.68, 55.49],
    'SG' => [1.35, 103.82], 'SM' => [43.94, 12.46], 'ST' => [0.19, 6.61], 'TO' => [-21.18, -175.2],
    'VC' => [13.25, -61.2], 'WS' => [-13.76, -172.1], 'BN' => [4.54, 114.73], 'TT' => [10.69, -61.22],
];

/**
 * Project a longitude and latitude with Equal Earth, before scaling.
 *
 * @return array{float, float}
 */
function equalEarth(float $longitude, float $latitude): array
{
    [$a1, $a2, $a3, $a4] = [1.340264, -0.081106, 0.000893, 0.003796];
    $theta = asin(sqrt(3) / 2 * sin(deg2rad($latitude)));
    $t2 = $theta ** 2;
    $t6 = $t2 ** 3;

    return [
        2 * sqrt(3) * deg2rad($longitude) * cos($theta) / (3 * ($a1 + 3 * $a2 * $t2 + $t6 * (7 * $a3 + 9 * $a4 * $t2))),
        $theta * ($a1 + $a2 * $t2 + $t6 * ($a3 + $a4 * $t2)),
    ];
}

/**
 * Project a longitude and latitude onto the map: WIDTH wide, TOP_LATITUDE at its top edge.
 *
 * @return array{float, float}
 */
function project(float $longitude, float $latitude): array
{
    static $xMax = null, $yTop = null;
    $xMax ??= equalEarth(180, 0)[0];
    $yTop ??= equalEarth(0, TOP_LATITUDE)[1];
    $scale = WIDTH / (2 * $xMax);
    [$x, $y] = equalEarth($longitude, $latitude);

    return [($x + $xMax) * $scale, ($yTop - $y) * $scale];
}

/**
 * Simplify a line, keeping the points further than TOLERANCE from the chord (Douglas-Peucker).
 *
 * @param  list<array{float, float}>  $points
 * @return list<array{float, float}>
 */
function simplify(array $points): array
{
    if (count($points) < 3) {
        return $points;
    }

    [$ax, $ay] = $points[0];
    [$bx, $by] = $points[count($points) - 1];
    $length = hypot($bx - $ax, $by - $ay);
    $furthest = 0;
    $index = 0;

    for ($i = 1; $i < count($points) - 1; $i++) {
        [$px, $py] = $points[$i];
        $distance = $length == 0.0 ? hypot($px - $ax, $py - $ay) : abs(($bx - $ax) * ($ay - $py) - ($ax - $px) * ($by - $ay)) / $length;

        if ($distance > $furthest) {
            [$furthest, $index] = [$distance, $i];
        }
    }

    if ($furthest <= TOLERANCE) {
        return [$points[0], $points[count($points) - 1]];
    }

    return [...array_slice(simplify(array_slice($points, 0, $index + 1)), 0, -1), ...simplify(array_slice($points, $index))];
}

/**
 * The signed area and centre of a ring (shoelace).
 *
 * @param  list<array{float, float}>  $ring
 * @return array{float, float, float}
 */
function areaAndCentre(array $ring): array
{
    $area = $cx = $cy = 0.0;

    for ($i = 0, $n = count($ring); $i < $n; $i++) {
        [$x1, $y1] = $ring[$i];
        [$x2, $y2] = $ring[($i + 1) % $n];
        $cross = $x1 * $y2 - $x2 * $y1;
        $area += $cross;
        $cx += ($x1 + $x2) * $cross;
        $cy += ($y1 + $y2) * $cross;
    }

    $area /= 2;

    return $area == 0.0 ? [0.0, $ring[0][0], $ring[0][1]] : [abs($area), $cx / (6 * $area), $cy / (6 * $area)];
}

/**
 * Write a ring as relative SVG path commands, one decimal.
 *
 * @param  list<array{float, float}>  $ring
 */
function ringPath(array $ring): string
{
    $rounded = array_map(fn (array $point) => [(int) round($point[0] * 10), (int) round($point[1] * 10)], $ring);
    $number = fn (int $tenths) => rtrim(rtrim(number_format($tenths / 10, 1, '.', ''), '0'), '.') ?: '0';
    [$x, $y] = $rounded[0];
    $path = 'M'.$number($x).' '.$number($y);
    $steps = [];

    foreach (array_slice($rounded, 1) as [$nx, $ny]) {
        if ($nx === $x && $ny === $y) {
            continue;
        }

        $dx = $number($nx - $x);
        $dy = $number($ny - $y);
        $steps[] = $dx.(str_starts_with($dy, '-') ? '' : ' ').$dy;
        [$x, $y] = [$nx, $ny];
    }

    return $path.'l'.implode(' ', $steps).'z';
}

$source = $argv[1] ?? null;

if ($source === null || ! is_file($source)) {
    fwrite(STDERR, "Usage: php tools/world-map.php ne_110m_admin_0_countries.geojson\n");
    exit(1);
}

$features = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR)['features'];
$paths = $points = [];
$bottom = 0.0;

foreach ($features as $feature) {
    $properties = $feature['properties'];
    $code = null;

    foreach ([$properties['ISO_A2'] ?? null, $properties['ISO_A2_EH'] ?? null] as $candidate) {
        if (is_string($candidate) && preg_match('/^[A-Z]{2}$/', $candidate)) {
            $code ??= $candidate;
        }
    }

    if ($code === null || in_array($code, SKIPPED, true)) {
        continue;
    }

    $polygons = $feature['geometry']['type'] === 'Polygon' ? [$feature['geometry']['coordinates']] : $feature['geometry']['coordinates'];
    $path = '';
    $largest = [0.0, 0.0, 0.0];

    foreach ($polygons as $polygon) {
        foreach ($polygon as $ringIndex => $ring) {
            $projected = simplify(array_map(fn (array $point) => project($point[0], $point[1]), $ring));
            [$area, $cx, $cy] = areaAndCentre($projected);

            if (count($projected) < 4 || $area < MIN_AREA) {
                continue;
            }

            if ($ringIndex === 0 && $area > $largest[0]) {
                $largest = [$area, $cx, $cy];
            }

            $path .= ringPath($projected);
            $bottom = max($bottom, ...array_column($projected, 1));
        }
    }

    if ($path !== '') {
        $paths[$code] = ($paths[$code] ?? '').$path;
        $points[$code] = [round($largest[1], 1), round($largest[2], 1)];
    }
}

$dots = [];

foreach (SMALL_COUNTRIES as $code => [$latitude, $longitude]) {
    if (! isset($paths[$code])) {
        $points[$code] = array_map(fn (float $value) => round($value, 1), project($longitude, $latitude));
        $dots[] = $code;
    }
}

ksort($paths);
ksort($points);
sort($dots);

$height = ceil($bottom + 4);
$output = dirname(__DIR__).'/resources/data/world-110m.json';
@mkdir(dirname($output), 0755, true);
file_put_contents($output, json_encode(['width' => WIDTH, 'height' => $height, 'paths' => $paths, 'points' => $points, 'dots' => $dots], JSON_UNESCAPED_SLASHES)."\n");

printf("%d countries, %d dots, %s KB: %s\n", count($paths), count($dots), number_format(filesize($output) / 1024, 1), $output);
