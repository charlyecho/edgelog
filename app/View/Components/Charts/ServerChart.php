<?php

namespace App\View\Components\Charts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\View\Component;

/**
 * One figure of the site's server over time: one or two lines on one axis
 * (a share in percent, a load, or bytes a second), a peak drawn dashed in
 * the colour of its average, and maybe a guide (the cores, on the load).
 * A point without a sample breaks the lines, never drops them to zero.
 */
class ServerChart extends Component
{
    public const int WIDTH = 600;

    public const int HEIGHT = 200;

    public const int LEFT = 60;

    public const int RIGHT = 16;

    public const int TOP = 12;

    public const int BOTTOM = 28;

    /**
     * @param  list<array<string, mixed>>  $points  each with its start ('at') and the lines' keys
     * @param  list<array{key: string, label: string, color: string, dashed?: bool, area?: bool}>  $lines
     * @param  'percent'|'load'|'bytes'  $unit
     * @param  ?array{value: float, label: string}  $guide
     */
    public function __construct(
        public string $title,
        public array $points,
        public array $lines,
        public string $unit,
        public int $step,
        public string $timezone,
        public ?float $fixedCeiling = null,
        public ?array $guide = null,
    ) {}

    /**
     * The top of the axis: the fixed one, or a round number at or above the largest value.
     */
    public function ceiling(): float
    {
        if ($this->fixedCeiling !== null) {
            return $this->fixedCeiling;
        }

        $values = [$this->guide['value'] ?? 0];

        foreach ($this->lines as $line) {
            foreach (array_column($this->points, $line['key']) as $value) {
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        $largest = max($values);

        if ($largest <= 0) {
            return 1;
        }

        // Bytes are shown in KB, MB... of 1024: round in that unit, so the axis reads 512 KB/s, not 488.3 KB/s.
        $scale = $this->unit === 'bytes' ? 1024 ** max(0, floor(log($largest, 1024))) : 1;
        $step = 10 ** floor(log10($largest / $scale));

        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            if ($multiple * $step * $scale >= $largest) {
                return $multiple * $step * $scale;
            }
        }

        return $largest;
    }

    /**
     * Determine if any line has a value in the range.
     */
    public function hasValues(): bool
    {
        foreach ($this->lines as $line) {
            if (array_filter(array_column($this->points, $line['key']), fn ($value) => $value !== null) !== []) {
                return true;
            }
        }

        return false;
    }

    public function x(int $index): float
    {
        $count = max(1, count($this->points) - 1);

        return round(self::LEFT + $index * (self::WIDTH - self::LEFT - self::RIGHT) / $count, 1);
    }

    public function y(float $value): float
    {
        return round(self::HEIGHT - self::BOTTOM - min($value, $this->ceiling()) / $this->ceiling() * (self::HEIGHT - self::TOP - self::BOTTOM), 1);
    }

    /**
     * The line of one key, as SVG path data, broken at every point without a sample.
     */
    public function path(string $key): string
    {
        $path = '';
        $drawing = false;

        foreach ($this->points as $index => $point) {
            if ($point[$key] === null) {
                $drawing = false;

                continue;
            }

            $path .= ($drawing ? 'L' : 'M').$this->x($index).' '.$this->y($point[$key]).' ';
            $drawing = true;
        }

        return trim($path);
    }

    /**
     * The area under one key, closed on the baseline, broken like its line.
     */
    public function area(string $key): string
    {
        $area = '';
        $run = [];
        $base = self::HEIGHT - self::BOTTOM;

        foreach ([...$this->points, [$key => null]] as $index => $point) {
            if ($point[$key] !== null) {
                $run[] = [$this->x($index), $this->y($point[$key])];

                continue;
            }

            if (count($run) > 1) {
                $area .= 'M'.$run[0][0].' '.$base.' ';

                foreach ($run as [$x, $y]) {
                    $area .= "L{$x} {$y} ";
                }

                $area .= 'L'.$run[array_key_last($run)][0].' '.$base.' Z ';
            }

            $run = [];
        }

        return trim($area);
    }

    /**
     * The points drawn alone, between two without a sample: a dot, or they would not show.
     *
     * @return list<int>
     */
    public function isolated(string $key): array
    {
        $alone = [];

        foreach ($this->points as $index => $point) {
            $before = $this->points[$index - 1][$key] ?? null;
            $after = $this->points[$index + 1][$key] ?? null;

            if ($point[$key] !== null && $before === null && $after === null) {
                $alone[] = $index;
            }
        }

        return $alone;
    }

    /**
     * One value in the chart's unit.
     */
    public function format(int|float|null $value): string
    {
        return match (true) {
            $value === null => '–',
            $this->unit === 'percent' => Number::format($value, maxPrecision: 1).'%',
            $this->unit === 'bytes' => __(':size/s', ['size' => Number::fileSize($value, maxPrecision: 1)]),
            default => Number::format($value, precision: 2),
        };
    }

    /**
     * The values marked on the axis.
     *
     * @return list<float>
     */
    public function axis(): array
    {
        $ceiling = $this->ceiling();

        return [0, $ceiling / 2, $ceiling];
    }

    /**
     * A few times along the bottom, where the site is.
     *
     * @return array<int, string>
     */
    public function ticks(): array
    {
        $last = count($this->points) - 1;
        $every = max(1, (int) ceil(count($this->points) / 5));
        $ticks = [];

        for ($index = 0; $index <= $last; $index += $every) {
            $ticks[$index] = $this->when($this->points[$index]['at'], short: true);
        }

        return $ticks;
    }

    /**
     * What the hover layer and the table show for each point: its time and
     * each line's value, written out in the order of the lines.
     *
     * @return list<array{x: float, label: string, values: list<string>}>
     */
    public function hoverPoints(): array
    {
        return array_map(fn (array $point, int $index) => [
            'x' => $this->x($index),
            'label' => $this->when($point['at']),
            'values' => array_map(fn (array $line) => $this->format($point[$line['key']]), $this->lines),
        ], $this->points, array_keys($this->points));
    }

    /**
     * Each line's label and colour, for the tooltip and the table.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function legend(): array
    {
        return array_map(fn (array $line) => [$line['label'], $line['color']], $this->lines);
    }

    /**
     * The start of a point, where the site is: a time within a day, a date further.
     */
    public function when(int $at, bool $short = false): string
    {
        $time = CarbonImmutable::createFromTimestamp($at, $this->timezone);

        return match (true) {
            $this->step >= 86400 => $time->translatedFormat($short ? 'j M' : 'D j M Y'),
            $this->step >= 3600 => $time->translatedFormat($short ? 'j M' : 'D j M, H:i'),
            default => $time->translatedFormat($short ? 'H:i' : 'D j M, H:i'),
        };
    }

    public function render(): View|Closure|string
    {
        return view('components.charts.server-chart');
    }
}
