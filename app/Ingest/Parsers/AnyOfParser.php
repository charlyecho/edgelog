<?php

namespace App\Ingest\Parsers;

use App\Ingest\LogHit;

/**
 * Reads a line with the first of its parsers that can, richest first.
 */
final readonly class AnyOfParser implements LogLineParser
{
    /**
     * @param  list<LogLineParser>  $parsers
     */
    public function __construct(private array $parsers) {}

    public function parse(string $line): ?LogHit
    {
        foreach ($this->parsers as $parser) {
            $hit = $parser->parse($line);

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function timestamp(string $line): ?int
    {
        foreach ($this->parsers as $parser) {
            $timestamp = $parser->timestamp($line);

            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return null;
    }
}
