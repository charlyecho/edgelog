<?php

namespace App\Analytics;

use App\Enums\SourceType;

/**
 * The visitor flow: each visit once, where it came from, then its pages in
 * order (a page loaded twice in a row counts once), then where it left.
 * Each column keeps its biggest values and folds the rest, so every column
 * adds up to the visits still there; a visit that left is drawn once, in
 * the column after its last page.
 *
 * Laid out for an SVG: nodes stacked per column, bands as wide as their visits.
 */
final class VisitorFlow
{
    public const int KEEP = 7;

    public const array DEPTHS = [3, 4, 5];

    public const string OTHER = "\0other";

    public const string MORE = "\0more";

    public const string LEFT = "\0left";

    public const int WIDTH = 1000;

    public const int HEIGHT = 340;

    private const int NODE = 8;

    private const int PAD = 10;

    private const int LABELS = 170;

    public int $visits = 0;

    /**
     * Each column's nodes, top to bottom.
     *
     * @var list<list<array{key: string, label: string, kind: string, count: int, x: float, y: float, h: float}>>
     */
    public array $columns = [];

    /**
     * @var list<array{from: array{0: int, 1: string}, to: array{0: int, 1: string}, count: int, path: string}>
     */
    public array $links = [];

    /**
     * @param  iterable<array{start: string, trail: list<string>}>  $visits
     */
    public function __construct(iterable $visits, public readonly int $depth = 4, public readonly string $start = 'source')
    {
        $paths = [];

        foreach ($visits as $visit) {
            $pages = [];

            foreach ($visit['trail'] as $page) {
                if (end($pages) !== $page) {
                    $pages[] = $page;
                }
            }

            $paths[] = [$visit['start'], ...$pages];
        }

        $this->visits = count($paths);

        if ($this->visits > 0) {
            $this->build($this->fold($paths));
            $this->layout();
        }
    }

    /**
     * The column headings: Source, Page 1 ... Page n, After.
     *
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [$this->start === 'via' ? __('Who sent them') : __('Source')];

        for ($page = 1; $page <= $this->depth; $page++) {
            $headings[] = __('Page :number', ['number' => $page]);
        }

        return [...$headings, __('After')];
    }

    /**
     * The x of each column.
     *
     * @return list<float>
     */
    public function columnX(): array
    {
        $step = (self::WIDTH - self::LABELS - self::NODE) / ($this->depth + 1);

        return array_map(fn (int $column) => round($column * $step, 1), range(0, $this->depth + 1));
    }

    /**
     * Each visit's nodes, column by column, with the small values of each
     * column folded: a column's own page counts, never another's.
     *
     * @param  list<list<string>>  $paths
     * @return list<list<string>>
     */
    private function fold(array $paths): array
    {
        for ($column = 0; $column <= $this->depth; $column++) {
            $counts = [];

            foreach ($paths as $path) {
                if (isset($path[$column])) {
                    $counts[$path[$column]] = ($counts[$path[$column]] ?? 0) + 1;
                }
            }

            arsort($counts);
            $kept = array_slice($counts, 0, count($counts) > self::KEEP + 1 ? self::KEEP : self::KEEP + 1, true);

            foreach ($paths as &$path) {
                if (isset($path[$column]) && ! isset($kept[$path[$column]])) {
                    $path[$column] = self::OTHER;
                }
            }
            unset($path);
        }

        return $paths;
    }

    /**
     * @param  list<list<string>>  $paths
     */
    private function build(array $paths): void
    {
        $nodes = [];
        $links = [];

        foreach ($paths as $path) {
            $length = count($path) - 1;
            $steps = array_slice($path, 0, $this->depth + 1);

            if ($length < $this->depth) {
                $steps[] = self::LEFT.$length;
            } else {
                $steps[] = $length > $this->depth ? self::MORE : self::LEFT.$length;
            }

            foreach ($steps as $column => $key) {
                $nodes[$column][$key] = ($nodes[$column][$key] ?? 0) + 1;

                if ($column > 0) {
                    $link = ($column - 1)."\0".$steps[$column - 1]."\1".$key;
                    $links[$link] = ($links[$link] ?? 0) + 1;
                }
            }
        }

        foreach ($nodes as $column => $counts) {
            uksort($counts, fn (string $a, string $b) => [$this->rank($a), -$counts[$a]] <=> [$this->rank($b), -$counts[$b]]);

            $this->columns[$column] = array_map(fn (string $key, int $count) => [
                'key' => $key, 'label' => $this->label($key, $column), 'kind' => $this->kind($key, $column), 'count' => $count, 'x' => 0.0, 'y' => 0.0, 'h' => 0.0,
            ], array_keys($counts), $counts);
        }

        foreach ($links as $link => $count) {
            [$from, $to] = explode("\1", $link);
            [$column, $key] = explode("\0", $from, 2);
            $this->links[] = ['from' => [(int) $column, $key], 'to' => [(int) $column + 1, $to], 'count' => $count, 'path' => ''];
        }
    }

    /**
     * Place the nodes and draw the bands between them.
     */
    private function layout(): void
    {
        $xs = $this->columnX();
        $most = max(array_map('count', $this->columns));
        $scale = (self::HEIGHT - self::PAD * ($most - 1)) / $this->visits;
        $index = [];

        foreach ($this->columns as $column => &$nodes) {
            $y = 0.0;

            foreach ($nodes as $position => &$node) {
                $node['x'] = $xs[$column];
                $node['y'] = round($y, 2);
                $node['h'] = round(max(1.0, $node['count'] * $scale), 2);
                $y += $node['count'] * $scale + self::PAD;
                $index[$column][$node['key']] = $position;
            }
        }
        unset($nodes, $node);

        $order = fn (array $link, string $side) => $index[$link[$side][0]][$link[$side][1]];
        usort($this->links, fn (array $a, array $b) => [$order($a, 'from'), $order($a, 'to')] <=> [$order($b, 'from'), $order($b, 'to')]);

        $out = [];
        $in = [];

        foreach ($this->links as $position => $link) {
            [$fromColumn, $fromKey] = $link['from'];
            $source = $this->columns[$fromColumn][$index[$fromColumn][$fromKey]];
            $width = $link['count'] * $scale;

            $y0 = $source['y'] + ($out[$fromColumn][$fromKey] ?? 0);
            $out[$fromColumn][$fromKey] = ($out[$fromColumn][$fromKey] ?? 0) + $width;
            $this->links[$position]['y1'] = $y0;
            $this->links[$position]['width'] = $width;
        }

        uasort($this->links, fn (array $a, array $b) => [$order($a, 'to'), $order($a, 'from')] <=> [$order($b, 'to'), $order($b, 'from')]);

        foreach ($this->links as $position => $link) {
            [$toColumn, $toKey] = $link['to'];
            $target = $this->columns[$toColumn][$index[$toColumn][$toKey]];
            $y1 = $target['y'] + ($in[$toColumn][$toKey] ?? 0);
            $in[$toColumn][$toKey] = ($in[$toColumn][$toKey] ?? 0) + $link['width'];

            $x0 = $xs[$link['from'][0]] + self::NODE;
            $x1 = $xs[$toColumn];
            $middle = ($x0 + $x1) / 2;
            $top0 = $link['y1'];
            $bottom0 = $top0 + $link['width'];
            $bottom1 = $y1 + $link['width'];

            $this->links[$position]['path'] = sprintf(
                'M%.1f %.1fC%.1f %.1f %.1f %.1f %.1f %.1fL%.1f %.1fC%.1f %.1f %.1f %.1f %.1f %.1fZ',
                $x0, $top0, $middle, $top0, $middle, $y1, $x1, $y1,
                $x1, $bottom1, $middle, $bottom1, $middle, $bottom0, $x0, $bottom0,
            );
        }

        $this->links = array_values($this->links);
    }

    /**
     * Pages and sources first, then the folded ones, then those who went on, then those who left.
     */
    private function rank(string $key): int
    {
        return match (true) {
            $key === self::OTHER => 1,
            $key === self::MORE => 2,
            str_starts_with($key, self::LEFT) => 3,
            default => 0,
        };
    }

    private function kind(string $key, int $column): string
    {
        return match (true) {
            $key === self::OTHER => 'other',
            $key === self::MORE => 'more',
            str_starts_with($key, self::LEFT) => 'left',
            $column === 0 => 'source',
            default => 'page',
        };
    }

    private function label(string $key, int $column): string
    {
        return match (true) {
            $key === self::OTHER => $column === 0 ? __('Other sources') : __('Other pages'),
            $key === self::MORE => __('Went on to more pages'),
            str_starts_with($key, self::LEFT) => trans_choice('Left after :count page|Left after :count pages', (int) substr($key, strlen(self::LEFT))),
            $column === 0 && $this->start === 'source' => SourceType::tryFrom($key)?->label() ?? $key,
            $column === 0 && $key === '' => __('Not recorded'),
            default => $key,
        };
    }
}
