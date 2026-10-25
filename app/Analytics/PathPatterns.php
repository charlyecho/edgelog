<?php

namespace App\Analytics;

/**
 * A list of path patterns a site set, matched against a request's whole
 * path, without the query. A star stands for any characters, slashes
 * included: "/user/home_ajax/" followed by a star matches every day's.
 */
abstract readonly class PathPatterns
{
    /**
     * The most patterns a site may have.
     */
    public const int MAX = 20;

    private ?string $pattern;

    /**
     * @param  list<string>  $patterns
     */
    public function __construct(public array $patterns = [])
    {
        $this->pattern = $patterns === [] ? null : '~^(?:'.implode('|', array_map(
            fn (string $pattern) => str_replace('\*', '.*', preg_quote($pattern, '~')),
            $patterns,
        )).')$~';
    }

    public function matches(string $path): bool
    {
        return $this->pattern !== null && preg_match($this->pattern, $path) === 1;
    }

    /**
     * Read the patterns typed one per line: trimmed, empty lines and repeats dropped.
     *
     * @return list<string>
     */
    public static function parse(string $lines): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $lines) ?: []), fn (string $line) => $line !== '')));
    }

    /**
     * Determine if a pattern is one a site may keep: a path from the root,
     * or one starting with *, that leaves some pages pages.
     */
    public static function isValid(string $pattern): bool
    {
        return strlen($pattern) <= 200
            && preg_match('~^[/*][^\s]*$~', $pattern) === 1
            && trim($pattern, '/*') !== '';
    }
}
