<?php

namespace App\Analytics\Health;

use App\Enums\LogFormat;
use App\Ingest\LogHit;

/**
 * Is the log the day was counted from whole? The audit proves the figures
 * agree with each other; it cannot prove the lines they came from were all
 * there. This counts what can be seen of that, over every line of the
 * window whatever its host (Skald's quality.py):
 *
 *  duplicates   lines identical, field for field, to an earlier one in the
 *               same second: counted, never dropped (without a request id
 *               a real repeat and a doubled line look the same)
 *  short        lines in a combined format: no host, no answer time
 *  silences     stretches of more than SILENT_MINUTES with no line at all
 */
final class QualityCollector
{
    public const int SILENT_MINUTES = 10;

    /**
     * Seconds back a line is compared to: nginx writes a line when its
     * request ends, so they come a little out of order.
     */
    private const int REMEMBERED_SECONDS = 600;

    private int $duplicates = 0;

    private int $short = 0;

    /**
     * Every minute with a line.
     *
     * @var array<int, true>
     */
    private array $minutes = [];

    /**
     * The lines seen, by second: [second][fields] => true.
     *
     * @var array<int, array<string, true>>
     */
    private array $seen = [];

    private int $prunedAt = PHP_INT_MIN;

    public function add(LogHit $hit): void
    {
        $second = $hit->when->getTimestamp();
        $fields = implode("\0", [$hit->address, $hit->host, $hit->method, $hit->url, $hit->status, $hit->size,
            $hit->referrer, $hit->userAgent, $hit->country, $hit->requestTime, $hit->requestId, $hit->format->value]);

        if (isset($this->seen[$second][$fields])) {
            $this->duplicates++;
        }

        $this->seen[$second][$fields] = true;

        if ($second > $this->prunedAt + 60) {
            $this->prunedAt = $second;
            $this->seen = array_filter($this->seen, fn (int $at) => $at >= $second - self::REMEMBERED_SECONDS, ARRAY_FILTER_USE_KEY);
        }

        $this->short += in_array($hit->format, [LogFormat::NginxCombined, LogFormat::ApacheCombined], true) ? 1 : 0;
        $this->minutes[intdiv($second, 60)] = true;
    }

    /**
     * The day's quality: duplicates, short lines, and each silence.
     *
     * @return array{duplicates: int, short: int, silences: list<array{from: int, to: int, minutes: int}>}
     */
    public function finish(): array
    {
        $minutes = array_keys($this->minutes);
        sort($minutes);
        $silences = [];

        foreach (array_slice($minutes, 1) as $index => $minute) {
            $silent = $minute - $minutes[$index] - 1;

            if ($silent > self::SILENT_MINUTES) {
                $silences[] = ['from' => ($minutes[$index] + 1) * 60, 'to' => $minute * 60, 'minutes' => $silent];
            }
        }

        return ['duplicates' => $this->duplicates, 'short' => $this->short, 'silences' => $silences];
    }
}
