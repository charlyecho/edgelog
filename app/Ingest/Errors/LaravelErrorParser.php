<?php

namespace App\Ingest\Errors;

/**
 * Laravel's laravel.log (Monolog's line format), an entry per line, an
 * exception followed by its stack trace:
 *
 *     [2026-10-25 14:57:19] production.ERROR: Message {"exception":"[object] (Class(code: 0): Message at /path/File.php:838)
 *     [stacktrace]
 *     #0 /path/File.php(794): ...
 *     "} {"url":"/checkout","ip":"203.0.113.7","http_method":"POST","server":"example.com","referrer":"https://example.com/cart"}
 *
 * The last JSON object is Monolog's extra: the request, when the channel has
 * the WebProcessor. Times without an offset are UTC.
 */
final readonly class LaravelErrorParser implements ErrorLogParser
{
    use ReadsErrorEntries;

    private const string HEADER = '/^\[(?<when>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:\.\d+)?(?<zone>Z|[+-]\d{2}:?\d{2})?\] \S+?\.(?<level>[A-Z]+): /';

    public function startsEntry(string $line): bool
    {
        return preg_match(self::HEADER, $line) === 1;
    }

    public function parse(string $entry): ?ErrorEntry
    {
        if (preg_match(self::HEADER, $entry, $header) !== 1 || ! in_array($header['level'], self::KEPT_LEVELS, true)) {
            return null;
        }

        $at = $this->time('Y-m-d H:i:s', str_replace('T', ' ', $header['when']), ($header['zone'] ?? '') === '' ? null : $header['zone']);

        if ($at === null) {
            return null;
        }

        $body = substr($entry, strlen($header[0]));
        $lines = explode("\n", rtrim($body, "\n"));
        $request = $this->request((string) end($lines));
        $thrown = $this->thrown($body);
        $marker = strpos($body, ' {"exception":"[object] (');

        if ($marker !== false) {
            $message = substr($body, 0, $marker);
        } else {
            $message = $lines[0];

            for ($i = 0; $i < 2; $i++) {
                $message = str_ends_with($message, ' []') ? substr($message, 0, -3) : ($this->trailingJson($message)[0] ?? $message);
            }
        }

        $stackAt = strpos($body, "\n[stacktrace]\n");
        $stack = '';

        if ($stackAt !== false) {
            $stack = substr($body, $stackAt + strlen("\n[stacktrace]\n"));
            $stack = $this->unescape(preg_replace('/\n"\}.*$/s', '', $stack));
        }

        return new ErrorEntry(
            at: $at,
            level: strtolower($header['level']),
            class: $thrown['class'],
            message: trim($message),
            file: $thrown['file'],
            line: $thrown['line'],
            stack: $this->stack($stack),
            method: $request['http_method'] ?? null,
            path: $request['url'] ?? null,
            host: $request['server'] ?? null,
            referrer: $request['referrer'] ?? null,
            address: $request['ip'] ?? null,
        );
    }

    /**
     * The request from Monolog's WebProcessor, in the extra that ends the
     * entry: a context of the app's own with a "url" is not taken for it.
     *
     * @return array<string, string>
     */
    private function request(string $lastLine): array
    {
        $extra = $this->trailingJson($lastLine)[1] ?? [];

        if (! isset($extra['url'], $extra['http_method']) || ! is_string($extra['url'])) {
            return [];
        }

        return array_filter(
            array_map(fn ($value) => is_string($value) && $value !== '' ? $value : null, array_intersect_key($extra, array_flip(['url', 'ip', 'http_method', 'server', 'referrer']))),
        );
    }
}
