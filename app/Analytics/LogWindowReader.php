<?php

namespace App\Analytics;

use App\Ingest\HistoryLog;
use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Ingest\Parsers\LogLineParser;
use Generator;

/**
 * Reads the lines of a window from a site's log files: the past logs' days
 * (HistoryLog), then the shipped ones, today's file, the day before's, and
 * the gzip'd ones further back, oldest first, each top to bottom.
 *
 * A file last written before the window began holds nothing of it and is
 * skipped. A later file is read whole: a shipper that was down sends
 * yesterday's lines today, with yesterday's times.
 */
final class LogWindowReader
{
    /**
     * Lines read in the window, unreadable lines placed by their neighbours.
     */
    public int $lines = 0;

    /**
     * Lines no parser of the site's format could read.
     */
    public int $unparsed = 0;

    /**
     * Whether any line older than the window was seen: the logs reach back
     * before its start, so the window is whole from its beginning.
     */
    public bool $reachesBack = false;

    /**
     * Where each file's lines stop being older than the window, found by the
     * first pass, and whether any were: the next pass starts there.
     *
     * @var array<int, array{offset: int, skipped: bool}>
     */
    private array $resumeAt = [];

    /**
     * The files every pass reads, opened by the first: a handle still reads
     * a file the ingest rotated away (access.log renamed to .1 at the first
     * batch of a new UTC day), and each pass stops where the first ended, so
     * the passes of a count always see the very same lines.
     *
     * @var ?list<array{handle: resource, gzip: bool, end: ?int}>
     */
    private ?array $snapshot = null;

    /**
     * Whether the snapshot's files left out older ones.
     */
    private bool $filesReachBack = false;

    public function __construct(
        private readonly string $folder,
        private readonly LogLineParser $parser,
        private readonly DayWindow $window,
    ) {}

    public function __destruct()
    {
        foreach ($this->snapshot ?? [] as $file) {
            $file['gzip'] ? gzclose($file['handle']) : fclose($file['handle']);
        }
    }

    /**
     * Every readable hit of the window, in file order.
     *
     * @return Generator<int, LogHit>
     */
    public function hits(): Generator
    {
        $this->lines = $this->unparsed = 0;
        $inWindow = false;
        $start = $this->window->start->getTimestamp();
        $files = $this->snapshot();
        $this->reachesBack = $this->filesReachBack;

        foreach (array_keys($files) as $key) {
            $resume = $this->resumeAt[$key] ?? ['offset' => 0, 'skipped' => false];
            $skipping = true;

            if ($resume['skipped']) {
                $this->reachesBack = true;
                $inWindow = false;
            }

            foreach ($this->linesOf($key, $resume['offset']) as $offset => $line) {
                $timestamp = trim($line) === '' ? null : $this->parser->timestamp($line);
                $older = $timestamp !== null && $timestamp < $start;

                if ($skipping && (trim($line) === '' || $older)) {
                    $resume = ['offset' => $offset + strlen($line), 'skipped' => $resume['skipped'] || $older];
                } else {
                    $skipping = false;
                }

                if (trim($line) === '') {
                    continue;
                }

                if ($older) {
                    $this->reachesBack = true;
                    $inWindow = false;

                    continue;
                }

                $hit = $this->parser->parse($line);

                if ($hit === null) {
                    if ($inWindow) {
                        $this->lines++;
                        $this->unparsed++;
                    }

                    continue;
                }

                if ($hit->when->lessThan($this->window->start)) {
                    $this->reachesBack = true;
                    $inWindow = false;

                    continue;
                }

                $inWindow = $this->window->contains($hit->when);

                if ($inWindow) {
                    $this->lines++;

                    yield $hit;
                }
            }

            $this->resumeAt[$key] = $resume;
        }
    }

    /**
     * The window's hits whose line holds the given text, in file order:
     * only those lines are parsed, so one address is found fast in a large log.
     *
     * @return Generator<int, LogHit>
     */
    public function hitsContaining(string $needle): Generator
    {
        foreach (array_keys($this->snapshot()) as $key) {
            foreach ($this->linesOf($key) as $line) {
                if (! str_contains($line, $needle)) {
                    continue;
                }

                $hit = $this->parser->parse($line);

                if ($hit !== null && $this->window->contains($hit->when)) {
                    yield $hit;
                }
            }
        }
    }

    /**
     * The site's files that may hold lines of the window, oldest first: the
     * past logs' days the window touches, then the shipped files.
     *
     * Past lines are all older than the first shipped one, so a window that
     * ends before it reads no shipped file.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $history = HistoryLog::in($this->folder);
        $firstDay = $this->window->start->utc()->toDateString();
        $files = $history->files($firstDay, $this->window->end->subSecond()->utc()->toDateString());

        if ($history->hasDayBefore($firstDay)) {
            $this->reachesBack = true;
        }

        $log = new IngestLog($this->folder);
        $firstShipped = $log->firstLineTime($this->parser);

        if ($firstShipped === null || $firstShipped >= $this->window->end->getTimestamp()) {
            return $files;
        }

        $shipped = $log->files();
        $recent = array_filter($shipped, fn (string $path) => (int) filemtime($path) >= $this->window->start->getTimestamp());

        if (count($recent) < count($shipped)) {
            $this->reachesBack = true;
        }

        return [...$files, ...array_values($recent)];
    }

    /**
     * The window's files, opened once: later passes read the same handles.
     *
     * @return list<array{handle: resource, gzip: bool, end: ?int}>
     */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $this->reachesBack = false;
        $this->snapshot = [];

        foreach ($this->files() as $path) {
            $gzip = str_ends_with($path, '.gz');
            $handle = @($gzip ? gzopen($path, 'rb') : fopen($path, 'rb'));

            if ($handle !== false) {
                $this->snapshot[] = ['handle' => $handle, 'gzip' => $gzip, 'end' => null];
            }
        }

        $this->filesReachBack = $this->reachesBack;

        return $this->snapshot;
    }

    /**
     * The lines of a snapshot file from the given byte, keyed by where each
     * starts, up to where the first full read of it ended.
     *
     * @return Generator<int, string>
     */
    private function linesOf(int $key, int $from = 0): Generator
    {
        ['handle' => $handle, 'gzip' => $gzip, 'end' => $end] = $this->snapshot[$key];
        $offset = $from;

        if ($gzip) {
            gzrewind($handle);

            if ($from > 0) {
                gzseek($handle, $from);
            }
        } else {
            fseek($handle, $from);
        }

        while (($end === null || $offset < $end) && ($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
            yield $offset => $line;

            $offset += strlen($line);
        }

        if ($end === null) {
            $this->snapshot[$key]['end'] = $offset;
        }
    }
}
