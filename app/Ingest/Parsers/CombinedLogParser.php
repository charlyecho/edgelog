<?php

namespace App\Ingest\Parsers;

use App\Enums\LogFormat;
use App\Ingest\LogHit;

/**
 * The stock "combined" format, used by nginx and Apache alike:
 *
 *     $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
 *
 * Many servers add fields after it; these are read when present: the time
 * taken right after the user agent, a quoted host name as the very last
 * field, rsc_prefetch=, request_length= and uid= (the signed-in user).
 */
final readonly class CombinedLogParser implements LogLineParser
{
    use ReadsLogFields;

    public function __construct(private LogFormat $format = LogFormat::NginxCombined) {}

    public function parse(string $line): ?LogHit
    {
        $pattern = '/^(?<address>\S+) \S+ \S+ \[(?<when>[^\]]+)\] "(?<request>'.self::QUOTED.')" '
            .'(?<status>\d{3}) (?<size>\S+) "(?<referrer>'.self::QUOTED.')" "(?<agent>'.self::QUOTED.')"(?<tail>.*)$/s';

        if (preg_match($pattern, rtrim($line, "\r\n"), $fields) !== 1) {
            return null;
        }

        $when = $this->time('d/M/Y:H:i:s O', $fields['when']);

        if ($when === null) {
            return null;
        }

        $tail = $fields['tail'];
        $rscPrefetch = $this->takeField($tail, 'rsc_prefetch');
        $requestLength = $this->takeField($tail, 'request_length');
        $userId = $this->takeField($tail, 'uid');
        $requestTime = preg_match('/^\s+(\d+(?:\.\d+)?)(?=\s|$)/', $tail, $time) === 1 ? $time[1] : null;
        $host = preg_match('/\s"((?=[^"]*[A-Za-z])[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)(?::\d+)?"\s*$/', $tail, $found) === 1
            ? strtolower($found[1])
            : '';

        [$method, $url] = $this->request($fields['request']);

        return new LogHit(
            when: $when,
            address: $fields['address'],
            host: $host,
            method: $method,
            url: $url,
            status: (int) $fields['status'],
            referrer: $this->orEmpty($fields['referrer']),
            userAgent: $this->orEmpty($fields['agent']),
            format: $this->format,
            requestTime: $this->seconds($requestTime),
            size: $this->bytes($fields['size']),
            rscPrefetch: $rscPrefetch,
            requestLength: $this->bytes($requestLength),
            userId: $this->userId($userId),
        );
    }
}
