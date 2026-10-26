<?php

namespace App\Ingest;

/**
 * Which part of a past log file a batch is, "<file id>:<start>-<end>": the
 * file's id (the sha256 of its first line, so it stays the same when
 * logrotate renames or compresses it, and while it grows), and where the
 * part starts and ends in the file's unpacked bytes.
 */
final readonly class HistoryRange
{
    /**
     * The header that carries it.
     */
    public const string HEADER = 'X-Edgelog-Range';

    public function __construct(public string $source, public int $start, public int $end) {}

    /**
     * Read the header's value, or return null when it is missing or malformed.
     */
    public static function tryFrom(?string $value): ?self
    {
        if (preg_match('/^(?<source>[0-9a-f]{64}):(?<start>\d{1,15})-(?<end>\d{1,15})$/', (string) $value, $parts) !== 1) {
            return null;
        }

        if ((int) $parts['end'] <= (int) $parts['start']) {
            return null;
        }

        return new self($parts['source'], (int) $parts['start'], (int) $parts['end']);
    }
}
