<?php

namespace App\Ingest\Parsers;

use App\Ingest\LogHit;

interface LogLineParser
{
    /**
     * Read one access log line, or return null when the line is not in this format.
     */
    public function parse(string $line): ?LogHit;
}
