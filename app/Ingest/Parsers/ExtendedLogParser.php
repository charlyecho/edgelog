<?php

namespace App\Ingest\Parsers;

use App\Enums\LogFormat;
use App\Ingest\LogHit;

/**
 * The extended nginx format, with the host, the time taken, the country and
 * a request id:
 *
 *     $time_iso8601 $remote_addr $host "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent" $request_time cc=$http_cf_ipcountry rid=$request_id
 */
final readonly class ExtendedLogParser implements LogLineParser
{
    use ReadsLogFields;

    /**
     * The nginx log_format that writes these lines.
     */
    public const string NGINX_LOG_FORMAT = "log_format edgelog_extended '\$time_iso8601 \$remote_addr \$host \"\$request\" '\n"
        ."                            '\$status \$body_bytes_sent \"\$http_referer\" \"\$http_user_agent\" '\n"
        ."                            '\$request_time cc=\$http_cf_ipcountry rid=\$request_id';";

    private const string TIME_FORMAT = 'Y-m-d\TH:i:sP';

    public function parse(string $line): ?LogHit
    {
        $pattern = '/^(?<when>\S+) (?<address>\S+) (?<host>\S+) "(?<request>'.self::QUOTED.')" '
            .'(?<status>\d{3}) (?<size>\S+) "(?<referrer>'.self::QUOTED.')" "(?<agent>'.self::QUOTED.')"(?<tail>.*)$/s';

        if (preg_match($pattern, rtrim($line, "\r\n"), $fields) !== 1) {
            return null;
        }

        $when = $this->time(self::TIME_FORMAT, $fields['when']);

        if ($when === null) {
            return null;
        }

        $tail = $fields['tail'];
        $rscPrefetch = $this->takeField($tail, 'rsc_prefetch');
        $requestLength = $this->takeField($tail, 'request_length');
        $userId = $this->takeField($tail, 'uid');
        $requestId = $this->takeField($tail, 'rid');
        $country = $this->takeField($tail, 'cc');
        $requestTime = preg_match('/^\s+(\S+)/', $tail, $time) === 1 ? $time[1] : null;

        [$method, $url] = $this->request($fields['request']);

        return new LogHit(
            when: $when,
            address: $fields['address'],
            host: strtolower($this->orEmpty($fields['host'])),
            method: $method,
            url: $url,
            status: (int) $fields['status'],
            referrer: $this->orEmpty($fields['referrer']),
            userAgent: $this->orEmpty($fields['agent']),
            format: LogFormat::NginxExtended,
            country: $this->country($country),
            requestTime: $this->seconds($requestTime),
            size: $this->bytes($fields['size']),
            requestId: preg_match('/^[0-9A-Za-z-]{8,64}$/', (string) $requestId) === 1 ? $requestId : '',
            rscPrefetch: $rscPrefetch,
            requestLength: $this->bytes($requestLength),
            userId: $this->userId($userId),
        );
    }

    public function timestamp(string $line): ?int
    {
        if (preg_match('/^(\d{4}-\d\d-\d\dT\S+) /', $line, $fields) !== 1) {
            return null;
        }

        return $this->unixTime(self::TIME_FORMAT, $fields[1]);
    }
}
