<?php

namespace App\Ingest\Errors;

/**
 * Craft CMS's storage/logs/web-YYYY-MM-DD.log (Monolog, in UTC), an entry a line:
 *
 *     2026-10-28 18:36:18 [web.ERROR] [yii\web\HttpException:403] Message {"trace":["#0 /path/File.php(1223): ..."],"memory":2099928,"exception":"[object] (yii\\web\\ForbiddenHttpException(code: 0): Message at /path/Controller.php:521)"}
 *
 * The category in brackets names the HTTP status of an HttpException.
 */
final readonly class CraftErrorParser implements ErrorLogParser
{
    use ReadsErrorEntries;

    private const string HEADER = '/^(?<when>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(?<channel>[^\]\s]+?)\.(?<level>[A-Z]+)\] (?:\[(?<category>[^\]]*)\] )?/';

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

        $body = rtrim(substr($entry, strlen($header[0])));
        $category = $header['category'] ?? '';
        $message = $body;
        $context = [];

        if (preg_match('/^(?<message>.*?) (?<context>\{"(?:trace|memory|exception|vars)".*)$/s', $body, $parts) === 1) {
            $message = $parts['message'];
            $context = json_decode($parts['context'], true) ?? json_decode($this->trailingJson($parts['context'])[0] ?? '', true) ?? [];
        }

        $thrown = $this->thrown(is_string($context['exception'] ?? null) ? $context['exception'] : $body);
        $trace = is_array($context['trace'] ?? null) ? array_filter($context['trace'], 'is_string') : [];
        $categoryClass = preg_replace('/:\d+$/', '', $category);

        return new ErrorEntry(
            at: $at,
            level: strtolower($header['level']),
            class: $thrown['class'] ?? (str_contains($categoryClass, '\\') ? $categoryClass : null),
            message: trim($message),
            file: $thrown['file'],
            line: $thrown['line'],
            stack: $this->stack(implode("\n", $trace)),
            status: preg_match('/HttpException:(?<status>[1-5]\d\d)$/', $category, $status) === 1 ? (int) $status['status'] : null,
        );
    }
}
