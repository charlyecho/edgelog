<?php

namespace App\Ingest;

use App\Enums\IngestRefusalReason;
use App\Ingest\Parsers\LogLineParser;

/**
 * A batch's body: one log line per line, plain or gzip'd.
 */
final readonly class BatchBody
{
    /**
     * Bytes of gzip fed to the inflater at once, so one step cannot unpack
     * much more than the limit before it is checked (a gzip bomb).
     */
    private const int INFLATE_STEP = 4096;

    private function __construct(public string $lines) {}

    /**
     * Unpack a body sent plain or with Content-Encoding: gzip, at most `$maxBytes` either way.
     *
     * @throws IngestRefusal
     */
    public static function unpack(string $raw, ?string $encoding, int $maxBytes): self
    {
        if (strlen($raw) > $maxBytes) {
            throw new IngestRefusal(IngestRefusalReason::TooLarge);
        }

        $encoding = strtolower(trim((string) $encoding));

        if ($encoding === '' || $encoding === 'identity') {
            return new self($raw);
        }

        if ($encoding !== 'gzip') {
            throw new IngestRefusal(IngestRefusalReason::BadEncoding);
        }

        return new self(self::gunzip($raw, $maxBytes));
    }

    /**
     * Keep the lines the parser can read; count, and drop, the others.
     *
     * A rejected line's content is never kept, logged or sent back.
     */
    public function accept(LogLineParser $parser, int $maxLineBytes): AcceptedLines
    {
        $kept = [];
        $rejected = 0;

        foreach (explode("\n", $this->lines) as $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '') {
                continue;
            }

            if (strlen($line) > $maxLineBytes || str_contains($line, "\0") || $parser->parse($line) === null) {
                $rejected++;

                continue;
            }

            $kept[] = $line;
        }

        return new AcceptedLines($kept === [] ? '' : implode("\n", $kept)."\n", count($kept), $rejected);
    }

    /**
     * @throws IngestRefusal
     */
    private static function gunzip(string $raw, int $maxBytes): string
    {
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        $unpacked = '';

        foreach (str_split($raw, self::INFLATE_STEP) as $chunk) {
            $part = @inflate_add($inflater, $chunk, ZLIB_SYNC_FLUSH);

            if ($part === false) {
                throw new IngestRefusal(IngestRefusalReason::BadBody);
            }

            $unpacked .= $part;

            if (strlen($unpacked) > $maxBytes) {
                throw new IngestRefusal(IngestRefusalReason::TooLarge);
            }

            if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                return $unpacked;
            }
        }

        throw new IngestRefusal(IngestRefusalReason::BadBody);
    }
}
