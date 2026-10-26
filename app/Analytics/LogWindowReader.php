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
     * @var array<string, array{offset: int, skipped: bool}>
     */
    private array $resumeAt = [];

    public function __construct(
        private readonly string $folder,
        private readonly LogLineParser $parser,
        private readonly DayWindow $window,
    ) {}

    /**
     * Every readable hit of the window, in file order.
     *
     * @return Generator<int, LogHit>
     */
    public function hits(): Generator
    {
        $this->lines = $this->unparsed = 0;
        $this->reachesBack = false;
        $inWindow = false;
        $start = $this->window->start->getTimestamp();
        $files = $this->files();

        foreach ($files as $file) {
            $key = $file.':'.fileinode($file);
            $resume = $this->resumeAt[$key] ?? ['offset' => 0, 'skipped' => false];
            $skipping = true;

            if ($resume['skipped']) {
                $this->reachesBack = true;
                $inWindow = false;
            }

            foreach ($this->linesOf($file, $resume['offset']) as $offset => $line) {
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
        foreach ($this->files() as $file) {
            foreach ($this->linesOf($file) as $line) {
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
     * The lines of the file from the given byte, keyed by where each starts.
     *
     * @return Generator<int, string>
     */
    private function linesOf(string $path, int $from = 0): Generator
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $offset = $from;

            if ($from > 0) {
                $gzip ? gzseek($handle, $from) : fseek($handle, $from);
            }

            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                yield $offset => $line;

                $offset += strlen($line);
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }
}
