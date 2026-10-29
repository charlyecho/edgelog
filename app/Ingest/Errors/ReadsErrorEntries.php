<?php

namespace App\Ingest\Errors;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * What the application logs share: their times, their levels, and the way
 * Monolog writes an exception into its context.
 */
trait ReadsErrorEntries
{
    /**
     * The levels kept: errors and exceptions, never warnings or notices.
     */
    protected const array KEPT_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /**
     * The most of a stack trace kept, in bytes.
     */
    protected const int MAX_STACK = 64 * 1024;

    /**
     * Read a time written in the given format and zone, in UTC, or null when it is not one.
     */
    protected function time(string $format, string $value, ?string $zone = null): ?CarbonImmutable
    {
        try {
            $timezone = new DateTimeZone($zone !== null && $zone !== '' ? $zone : 'UTC');
        } catch (Exception) {
            $timezone = new DateTimeZone('UTC');
        }

        $when = DateTimeImmutable::createFromFormat($format, $value, $timezone);

        if ($when === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return CarbonImmutable::instance($when)->utc();
    }

    /**
     * Read Monolog's "[object] (Class(code: 0): message at /file:12)": the
     * class, or null when it is not there (a very long line can lose it in its
     * middle; a "[previous exception]" is never taken for it), and the place.
     *
     * @return array{class: ?string, file: ?string, line: ?int}
     */
    protected function thrown(string $text): array
    {
        $class = preg_match('/(?<!\[previous exception\] )\[object\] \((?<class>[A-Za-z0-9_\\\\]+)\(code: [^)]*\): /', $text, $found) === 1 ? $this->unescape($found['class']) : null;
        $at = str_contains($text, '[object] (') && preg_match('/ at (?<file>[^\s()]+):(?<line>\d+)\)\s*(?:\n|"|$)/', $text, $place) === 1 ? $place : null;

        return [
            'class' => $class,
            'file' => $at === null ? null : $this->unescape($at['file']),
            'line' => $at === null ? null : (int) $at['line'],
        ];
    }

    /**
     * Undo the escaping of text written inside a JSON string.
     */
    protected function unescape(string $text): string
    {
        return strtr($text, ['\\\\' => '\\', '\\/' => '/', '\\"' => '"']);
    }

    /**
     * The stack trace, at most MAX_STACK bytes of it.
     */
    protected function stack(string $stack): string
    {
        return mb_strcut(trim($stack, "\n"), 0, self::MAX_STACK);
    }

    /**
     * Find the JSON object that ends the line (Monolog's context or extra),
     * and return it decoded with the text before it, or null when there is none.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function trailingJson(string $line): ?array
    {
        $line = rtrim($line);
        $at = strlen($line);

        while (($at = strrpos(substr($line, 0, $at), ' {')) !== false) {
            $decoded = json_decode(substr($line, $at + 1), true);

            if (is_array($decoded)) {
                return [substr($line, 0, $at), $decoded];
            }
        }

        return null;
    }
}
