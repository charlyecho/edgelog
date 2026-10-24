<?php

namespace App\Analytics;

use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Ingest\Parsers\LogLineParser;
use Generator;

/**
 * Reads the lines of a window from a site's shipped log files: today's
 * file, the day before's, and the gzip'd ones further back, oldest first,
 * each top to bottom.
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
        $files = $this->files();

        foreach ($files as $file) {
            foreach ($this->linesOf($file) as $line) {
                if (trim($line) === '') {
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
        }
    }

    /**
     * The site's files that may hold lines of the window, oldest first.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $base = "{$this->folder}/".IngestLog::BASE;
        $files = [];

        foreach (glob("{$base}.*.gz") ?: [] as $path) {
            if (preg_match('/\.(\d+)\.gz$/', $path, $number) === 1) {
                $files[(int) $number[1]] = $path;
            }
        }

        if (is_file("{$base}.1")) {
            $files[1] = "{$base}.1";
        }

        if (is_file($base)) {
            $files[0] = $base;
        }

        krsort($files);

        $recent = array_filter($files, fn (string $path) => (int) filemtime($path) >= $this->window->start->getTimestamp());

        if (count($recent) < count($files)) {
            $this->reachesBack = true;
        }

        return array_values($recent);
    }

    /**
     * @return Generator<int, string>
     */
    private function linesOf(string $path): Generator
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }
}
