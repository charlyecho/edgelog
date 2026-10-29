<?php

namespace App\Ingest\Errors;

/**
 * Cuts the text of an error log into its entries. The last one may still be
 * written (its stack trace in the next batch): it is held back as the tail,
 * and read once the next entry starts or it has waited long enough.
 */
final readonly class ErrorEntries
{
    /**
     * A tail longer than this is read at once, whole or not, in bytes.
     */
    public const int MAX_TAIL = 256 * 1024;

    /**
     * @param  list<string>  $entries  the whole entries, in log order
     */
    private function __construct(public array $entries, public string $tail) {}

    /**
     * Split the tail held before followed by the new lines. Lines before the
     * first entry (the end of one whose start was never sent) are dropped.
     */
    public static function split(ErrorLogParser $parser, string $text): self
    {
        $entries = [];
        $current = null;

        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line, "\r");

            if ($parser->startsEntry($line)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = $line;
            } elseif ($current !== null) {
                $current .= "\n".$line;
            }
        }

        $tail = $current === null ? '' : rtrim($current, "\n");

        if (strlen($tail) > self::MAX_TAIL) {
            $entries[] = $tail;
            $tail = '';
        }

        return new self($entries, $tail);
    }
}
