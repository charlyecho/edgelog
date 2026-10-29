<?php

namespace App\Ingest\Errors;

/**
 * PHP's own error log (php.ini error_log), its time zone named in each entry:
 *
 *     [29-Oct-2026 10:02:11 UTC] PHP Fatal error:  Uncaught Exception: Message in /path/index.php:12
 *     Stack trace:
 *     #0 {main}
 *       thrown in /path/index.php on line 12
 *
 * Warnings, notices and deprecations are left out, and so are PHP-FPM's own
 * lines. Text an app wrote there is kept: for "An Error occurred while
 * handling another error", the error it was handling.
 */
final readonly class PhpErrorParser implements ErrorLogParser
{
    use ReadsErrorEntries;

    private const string HEADER = '/^\[(?<when>\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: (?<zone>[^\]]+))?\] /';

    private const string THROWN = '/^(?:Uncaught )?(?<class>[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*): (?<message>.*?) in (?<file>\S+):(?<line>\d+)$/m';

    public function startsEntry(string $line): bool
    {
        return preg_match(self::HEADER, $line) === 1;
    }

    public function parse(string $entry): ?ErrorEntry
    {
        if (preg_match(self::HEADER, $entry, $header) !== 1) {
            return null;
        }

        $at = $this->time('d-M-Y H:i:s', $header['when'], $header['zone'] ?? null);
        $body = rtrim(substr($entry, strlen($header[0])), "\n");

        if ($at === null || preg_match('/^[A-Z]+: /', $body) === 1) {
            return null;
        }

        if (preg_match('/^PHP (?<type>[A-Za-z ]+?):\s+(?<rest>.*)$/s', $body, $php) === 1) {
            if (stripos($php['type'], 'error') === false) {
                return null;
            }

            $level = preg_match('/fatal|parse|core|compile/i', $php['type']) === 1 ? 'critical' : 'error';
            $body = $php['rest'];
        } else {
            $level = 'error';
            $previous = strrpos($body, "\nPrevious exception:\n");

            if ($previous !== false) {
                $body = substr($body, $previous + strlen("\nPrevious exception:\n"));
            } elseif (preg_match('/^[^\n]*:\n/', $body, $intro) === 1 && preg_match(self::THROWN, substr($body, strlen($intro[0]))) === 1) {
                $body = substr($body, strlen($intro[0]));
            }
        }

        [$first] = explode("\n", $body, 2);
        $class = null;
        $file = null;
        $line = null;
        $message = $first;

        if (preg_match(self::THROWN, $first, $thrown) === 1) {
            [$class, $message, $file, $line] = [$thrown['class'], $thrown['message'], $thrown['file'], (int) $thrown['line']];
        } elseif (preg_match('/^(?<message>.*) in (?<file>\S+) on line (?<line>\d+)$/', $first, $place) === 1) {
            [$message, $file, $line] = [$place['message'], $place['file'], (int) $place['line']];
        }

        $stack = preg_match('/\nStack trace:\n(?<stack>.*?)(?:\n  thrown in .*)?$/s', $body, $trace) === 1 ? $trace['stack'] : '';

        return new ErrorEntry(
            at: $at,
            level: $level,
            class: $class,
            message: trim($message),
            file: $file,
            line: $line,
            stack: $this->stack($stack),
        );
    }
}
