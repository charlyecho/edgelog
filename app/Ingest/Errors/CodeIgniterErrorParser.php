<?php

namespace App\Ingest\Errors;

/**
 * CodeIgniter 4's writable/logs/log-YYYY-MM-DD.log, in the app's time zone (UTC):
 *
 *     CRITICAL - 2026-10-29 07:39:23 --> CodeIgniter\Security\Exceptions\SecurityException: The action you requested is not allowed.
 *     [Method: POST, Route: user/project/autosave]
 *     in SYSTEMPATH/Security/Security.php on line 258.
 *      1 SYSTEMPATH/Security/Security.php(258): ...
 *
 *     ERROR - 2026-10-15 08:23:07 --> Error connecting to the database: mysqli_sql_exception: No such file or directory in /path/Connection.php:201
 *     Stack trace:
 *     #0 /path/Connection.php(201): mysqli->real_connect()
 */
final readonly class CodeIgniterErrorParser implements ErrorLogParser
{
    use ReadsErrorEntries;

    private const string HEADER = '/^(?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY) - (?<when>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --> /';

    public function startsEntry(string $line): bool
    {
        return preg_match(self::HEADER, $line) === 1;
    }

    public function parse(string $entry): ?ErrorEntry
    {
        if (preg_match(self::HEADER, $entry, $header) !== 1 || ! in_array($header['level'], self::KEPT_LEVELS, true)) {
            return null;
        }

        $at = $this->time('Y-m-d H:i:s', $header['when']);

        if ($at === null) {
            return null;
        }

        $lines = explode("\n", rtrim(substr($entry, strlen($header[0])), "\n"));
        $message = array_shift($lines);
        $class = null;
        $file = null;
        $line = null;

        if (preg_match('/^(?<class>\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*): (?<message>.*)$/s', $message, $thrown) === 1) {
            $class = ltrim($thrown['class'], '\\');
            $message = $thrown['message'];
        }

        if (preg_match('/^(?<message>.*) in (?<file>\S+):(?<line>\d+)$/', $message, $place) === 1) {
            [$message, $file, $line] = [$place['message'], $place['file'], (int) $place['line']];
        }

        $method = null;
        $path = null;
        $stack = [];

        foreach ($lines as $text) {
            if ($method === null && preg_match('/^\[Method: (?<method>[A-Z]+), Route: (?<route>.*)\]$/', $text, $route) === 1) {
                $method = $route['method'];
                $path = '/'.ltrim($route['route'], '/');
            } elseif ($file === null && preg_match('/^in (?<file>\S+) on line (?<line>\d+)\.?$/', $text, $place) === 1) {
                [$file, $line] = [$place['file'], (int) $place['line']];
            } elseif ($text !== 'Stack trace:') {
                $stack[] = $text;
            }
        }

        return new ErrorEntry(
            at: $at,
            level: strtolower($header['level']),
            class: $class,
            message: trim($message),
            file: $file,
            line: $line,
            stack: $this->stack(implode("\n", $stack)),
            method: $method,
            path: $path,
        );
    }
}
