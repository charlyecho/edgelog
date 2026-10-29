<?php

namespace App\Ingest\Errors;

interface ErrorLogParser
{
    /**
     * Determine if the line starts a new entry; the lines after it, up to the
     * next one that does, belong to it (its stack trace).
     */
    public function startsEntry(string $line): bool;

    /**
     * Read one whole entry, or return null when it is not an error or an
     * exception (a warning, a notice, a line of another format).
     */
    public function parse(string $entry): ?ErrorEntry;
}
