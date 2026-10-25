<?php

namespace App\Ingest\Parsers;

use App\Ingest\LogHit;

interface LogLineParser
{
    /**
     * Read one access log line, or return null when the line is not in this format.
     */
    public function parse(string $line): ?LogHit;

    /**
     * Read only the time of the line, as a Unix timestamp, or return null when
     * it has none in this format. Much cheaper than parsing the whole line.
     */
    public function timestamp(string $line): ?int;
}
