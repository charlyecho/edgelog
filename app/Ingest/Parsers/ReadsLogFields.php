<?php

namespace App\Ingest\Parsers;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * What the nginx and Apache formats share: the request field, the optional
 * fields after the user agent, and how empty values are written.
 */
trait ReadsLogFields
{
    /**
     * A quoted field, where a quote may be escaped with a backslash (Apache)
     * or as \x22 (nginx).
     */
    protected const QUOTED = '((?:[^"\\\\]|\\\\.)*)';

    /**
     * Read a timestamp written in the given format, in UTC, or null when it
     * is not one (a date that overflows, such as 31 February, is not).
     */
    protected function time(string $format, string $value): ?CarbonImmutable
    {
        $when = DateTimeImmutable::createFromFormat($format, $value);

        if ($when === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return CarbonImmutable::instance($when)->utc();
    }

    /**
     * Split "GET /path HTTP/1.1" into its method and URL. Anything else (an
     * empty request, bytes of TLS sent to port 80) is still a request the
     * server answered, kept with no method and no URL.
     *
     * @return array{0: string, 1: string}
     */
    protected function request(string $request): array
    {
        if (preg_match('/^([A-Z]+) (\S*)/', $request, $parts) !== 1) {
            return ['', ''];
        }

        return [$parts[1], $parts[2]];
    }

    /**
     * Take the `name=value` field out of the trailing fields, wherever it sits.
     */
    protected function takeField(string &$tail, string $name): ?string
    {
        if (! str_contains($tail, "{$name}=") || preg_match('/(?:^|\s)'.preg_quote($name, '/').'=(\S*)/', $tail, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $tail = substr($tail, 0, $found[0][1]).substr($tail, $found[0][1] + strlen($found[0][0]));

        return $found[1][0];
    }

    /**
     * A value logged as "-" (or nothing) means the field was empty.
     */
    protected function orEmpty(string $value): string
    {
        return $value === '-' ? '' : stripcslashes($value);
    }

    /**
     * The country code, never a guessed one: XX and ZZ are "unknown".
     */
    protected function country(?string $value): string
    {
        $value = trim((string) $value);

        return in_array($value, ['', '-', 'XX', 'ZZ'], true) ? '' : $value;
    }

    /**
     * The seconds the server took to answer, when the field is a sensible number.
     */
    protected function seconds(?string $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;

        return $seconds >= 0 && $seconds < 86400 ? $seconds : null;
    }

    /**
     * A byte count, when the field holds one.
     */
    protected function bytes(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
