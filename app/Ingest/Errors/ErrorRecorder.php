<?php

namespace App\Ingest\Errors;

use App\Enums\ErrorLogKind;
use App\Enums\ErrorStatus;
use App\Models\Site;
use App\Models\SiteErrorOccurrence;

/**
 * Keeps an error read from a log: one more occurrence of its group, made
 * when it is new. A resolved error that comes back is open again, marked a
 * regression; an ignored one stays ignored, counted all the same.
 */
final readonly class ErrorRecorder
{
    /**
     * The most of a message kept, in bytes.
     */
    private const int MAX_MESSAGE = 2000;

    public function __construct(private Site $site, private ErrorLogKind $kind) {}

    public function record(ErrorEntry $entry): SiteErrorOccurrence
    {
        $message = mb_strcut($entry->message, 0, self::MAX_MESSAGE);
        $file = ErrorFingerprint::relativePath($entry->file);
        $error = $this->site->errors()->firstOrNew(
            ['fingerprint' => ErrorFingerprint::of($entry)],
            [
                'kind' => $this->kind,
                'class' => $entry->class === null ? null : mb_strcut($entry->class, 0, 255),
                'file' => $file === null ? null : mb_strcut($file, 0, 500),
                'line' => $entry->line,
                'status' => ErrorStatus::Open,
                'first_seen_at' => $entry->at,
                'last_seen_at' => $entry->at,
            ],
        );

        if ($error->status === ErrorStatus::Resolved && $entry->at->greaterThan($error->resolved_at)) {
            $error->status = ErrorStatus::Open;
            $error->resolved_at = null;
            $error->regressed_at = now();
        }

        $error->level = $entry->level;
        $error->message = $message;
        $error->first_seen_at = $entry->at->lessThan($error->first_seen_at) ? $entry->at : $error->first_seen_at;
        $error->last_seen_at = $entry->at->greaterThan($error->last_seen_at) ? $entry->at : $error->last_seen_at;
        $error->times_seen++;

        if ($entry->stack !== '') {
            $error->last_stack = $entry->stack;
        }

        $error->save();

        return $error->occurrences()->create([
            'site_id' => $this->site->id,
            'at' => $entry->at,
            'kind' => $this->kind,
            'message' => $message,
            'method' => $entry->method === null ? null : substr($entry->method, 0, 10),
            'path' => $entry->path === null ? null : mb_strcut($entry->path, 0, 2000),
            'host' => $entry->host === null ? null : mb_strcut($entry->host, 0, 255),
            'referrer' => $entry->referrer === null ? null : mb_strcut($entry->referrer, 0, 2000),
            'user_agent' => $entry->userAgent === null ? null : mb_strcut($entry->userAgent, 0, 500),
            'address' => $entry->address === null ? null : substr($entry->address, 0, 45),
            'status' => $entry->status,
            'request_from' => $entry->knowsItsRequest() ? SiteErrorOccurrence::FROM_LOG : null,
            'looked_up_at' => $entry->knowsItsRequest() && $entry->address !== null ? now() : null,
            'stack' => $entry->stack === '' ? null : $entry->stack,
        ]);
    }
}
