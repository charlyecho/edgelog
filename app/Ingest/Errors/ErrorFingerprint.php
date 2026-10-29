<?php

namespace App\Ingest\Errors;

/**
 * What makes two errors the same one: the class thrown, where it was thrown
 * (the file's path from the project down) and the message with its numbers,
 * ids and data taken out.
 */
final class ErrorFingerprint
{
    /**
     * The most of the message that counts, in characters.
     */
    private const int MESSAGE = 300;

    public static function of(ErrorEntry $entry): string
    {
        return sha1(implode("\0", [
            $entry->class ?? '',
            self::relativePath($entry->file) ?? '',
            (string) $entry->line,
            self::message($entry->message),
        ]));
    }

    /**
     * The file's path from the project down: from vendor/ in, or without the
     * site's folder (and a release folder) on the usual servers.
     */
    public static function relativePath(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        $vendor = strpos($file, '/vendor/');

        if ($vendor !== false) {
            return substr($file, $vendor + 1);
        }

        return preg_replace('~^/(?:home/[^/]+|var/www(?:/html)?|srv(?:/www)?)/[^/]+/(?:releases/[^/]+/|current/)?~', '', $file);
    }

    /**
     * The message as it counts for the fingerprint.
     */
    public static function message(string $message): string
    {
        $message = preg_replace('/ \((?:Connection|SQL): .*$/s', '', $message);
        $message = preg_replace('~data:[\w/+.-]*;base64,[A-Za-z0-9+/=]+~', 'data:…', $message);
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{uuid}', $message);
        $message = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '{email}', $message);
        $message = preg_replace('/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i', '{hex}', $message);
        $message = preg_replace('/\d+(?:\.\d+)?/', '{n}', $message);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, self::MESSAGE);
    }
}
