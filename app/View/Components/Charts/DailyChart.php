<?php

namespace App\View\Components\Charts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Visitors and pages read, by day: two lines on one axis (both are counts
 * of the same people). A day with no figures breaks the lines, never drops
 * them to zero.
 */
class DailyChart extends Component
{
    public const int WIDTH = 1200;

    public const int HEIGHT = 260;

    public const int LEFT = 44;

    public const int RIGHT = 90;

    public const int TOP = 14;

    public const int BOTTOM = 30;

    /**
     * @param  list<array{day: string, status: ?string, visitors: ?int, pages: ?int, partial: bool}>  $series
     */
    public function __construct(public array $series) {}

    /**
     * The top of the axis: a round number at or above the largest value.
     */
    public function ceiling(): int
    {
        $largest = max([1, ...array_filter(array_column($this->series, 'pages'), 'is_int'), ...array_filter(array_column($this->series, 'visitors'), 'is_int')]);
        $step = 10 ** (int) floor(log10($largest));

        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            if ($multiple * $step >= $largest) {
                return (int) ceil($multiple * $step);
            }
        }

        return $largest;
    }

    public function x(int $index): float
    {
        $count = max(1, count($this->series) - 1);

        return round(self::LEFT + $index * (self::WIDTH - self::LEFT - self::RIGHT) / $count, 1);
    }

    public function y(int $value): float
    {
        return round(self::HEIGHT - self::BOTTOM - $value / $this->ceiling() * (self::HEIGHT - self::TOP - self::BOTTOM), 1);
    }

    /**
     * The line of one series, as SVG path data, broken at every day without figures.
     */
    public function path(string $key): string
    {
        $path = '';
        $drawing = false;

        foreach ($this->series as $index => $point) {
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
     * The area under one series, closed on the baseline, broken like its line.
     */
    public function area(string $key): string
    {
        $area = '';
        $run = [];
        $base = self::HEIGHT - self::BOTTOM;

        foreach ([...$this->series, ['visitors' => null, 'pages' => null]] as $index => $point) {
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
     * The days drawn alone, between two days without figures: a dot, or they would not show.
     *
     * @return list<int>
     */
    public function isolated(string $key): array
    {
        $alone = [];

        foreach ($this->series as $index => $point) {
            $before = $this->series[$index - 1][$key] ?? null;
            $after = $this->series[$index + 1][$key] ?? null;

            if ($point[$key] !== null && $before === null && $after === null) {
                $alone[] = $index;
            }
        }

        return $alone;
    }

    /**
     * The last day with figures, where the lines are labelled.
     */
    public function lastIndex(): ?int
    {
        for ($index = count($this->series) - 1; $index >= 0; $index--) {
            if ($this->series[$index]['visitors'] !== null) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A few dates along the bottom.
     *
     * @return array<int, string>
     */
    public function ticks(): array
    {
        $last = count($this->series) - 1;
        $every = max(1, (int) ceil(count($this->series) / 6));
        $ticks = [];

        for ($index = 0; $index <= $last; $index += $every) {
            $ticks[$index] = CarbonImmutable::parse($this->series[$index]['day'])->translatedFormat('j M');
        }

        $ticks[$last] = CarbonImmutable::parse($this->series[$last]['day'])->translatedFormat('j M');

        return $ticks;
    }

    /**
     * What the hover layer shows for each day.
     *
     * @return list<array{x: float, label: string, visitors: ?int, pages: ?int, note: ?string}>
     */
    public function points(): array
    {
        return array_map(fn (array $point, int $index) => [
            'x' => $this->x($index),
            'label' => CarbonImmutable::parse($point['day'])->translatedFormat('D j M'),
            'visitors' => $point['visitors'],
            'pages' => $point['pages'],
            'note' => match (true) {
                $point['status'] === 'withheld' => __('Withheld: the figures did not add up'),
                $point['status'] === 'collected' => __('Being checked'),
                $point['status'] === null => __('Not recorded'),
                $point['partial'] => __('Part of the day only'),
                default => null,
            },
        ], $this->series, array_keys($this->series));
    }

    public function render(): View|Closure|string
    {
        return view('components.charts.daily-chart');
    }
}
