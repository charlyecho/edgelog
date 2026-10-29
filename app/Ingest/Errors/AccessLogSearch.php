<?php

namespace App\Ingest\Errors;

use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Ingest\Parsers\LogLineParser;
use App\Models\Site;

/**
 * Finds the requests of a few seconds in a site's shipped access log, today's
 * file and the day before's: a binary search by time to a little before them,
 * then a read forward to a little after. Lines arrive nearly in time order
 * (a shipper sends its lines in the order the web server wrote them), so the
 * margins take in what came out of order.
 */
final readonly class AccessLogSearch
{
    /**
     * Seconds read before and after the window, for lines out of order.
     */
    private const int MARGIN = 120;

    /**
     * Below this many bytes, the rest is read rather than searched.
     */
    private const int READ_SPAN = 64 * 1024;

    public function __construct(private IngestLog $log, private LogLineParser $parser) {}

    public static function for(Site $site): self
    {
        return new self(IngestLog::for($site), $site->log_format->parser());
    }

    /**
     * The requests from `$from` to `$to`, both Unix times, included.
     *
     * @return list<LogHit>
     */
    public function between(int $from, int $to): array
    {
        $hits = [];

        foreach ([$this->log->path().'.1', $this->log->path()] as $file) {
            if (! is_file($file) || (int) filemtime($file) < $from) {
                continue;
            }

            $handle = fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            fseek($handle, $this->offsetBefore($handle, (int) filesize($file), $from - self::MARGIN));

            while (($line = fgets($handle)) !== false) {
                $time = $this->parser->timestamp($line);

                if ($time === null || $time < $from) {
                    continue;
                }

                if ($time > $to + self::MARGIN) {
                    break;
                }

                if ($time <= $to && ($hit = $this->parser->parse($line)) !== null) {
                    $hits[] = $hit;
                }
            }

            fclose($handle);
        }

        return $hits;
    }

    /**
     * Where a line older than `$time` starts, near the last such line.
     *
     * @param  resource  $handle
     */
    private function offsetBefore($handle, int $size, int $time): int
    {
        $low = 0;
        $high = $size;

        while ($high - $low > self::READ_SPAN) {
            $middle = intdiv($low + $high, 2);
            fseek($handle, $middle);
            fgets($handle);
            $start = ftell($handle);
            $line = fgets($handle);
            $lineTime = $line === false ? null : $this->parser->timestamp($line);

            if ($lineTime !== null && $lineTime >= $time) {
                $high = $middle;
            } else {
                $low = $start === false ? $middle : $start;
            }
        }

        if ($low > 0) {
            fseek($handle, $low - 1);

            if (fgetc($handle) !== "\n") {
                fgets($handle);

                return (int) ftell($handle);
            }
        }

        return $low;
    }
}
