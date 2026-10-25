<?php

namespace App\Analytics\Crawlers;

/**
 * A robots.txt read the way Google reads one (RFC 9309): a crawler follows
 * the group naming its token, else the "*" group; within it the longest
 * matching rule wins, Allow on a tie; * matches anything and a trailing $
 * ends the path. No group for it: everything is allowed.
 */
final readonly class RobotsTxt
{
    /**
     * @param  array<string, list<array{0: bool, 1: string}>>  $groups  user-agent token (lower case) => [allow, pattern] rules
     * @param  list<string>  $sitemaps
     */
    public function __construct(public array $groups, public array $sitemaps = []) {}

    public static function parse(string $body): self
    {
        $groups = [];
        $sitemaps = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);
                $groups[strtolower($value)] ??= [];
            } elseif (($field === 'allow' || $field === 'disallow') && $agents !== []) {
                $inRules = true;

                if ($value !== '') {
                    foreach ($agents as $agent) {
                        $groups[$agent][] = [$field === 'allow', $value];
                    }
                }
            } elseif ($field === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;
            }
        }

        return new self($groups, $sitemaps);
    }

    /**
     * The group a crawler follows: its own token, else "*", else none.
     */
    public function groupFor(string $crawler): ?string
    {
        $crawler = strtolower($crawler);

        foreach (array_keys($this->groups) as $token) {
            if ($token !== '*' && $token !== '' && ($crawler === $token || str_starts_with($crawler, $token))) {
                return $token;
            }
        }

        return isset($this->groups['*']) ? '*' : null;
    }

    public function allows(string $crawler, string $path): bool
    {
        $group = $this->groupFor($crawler);

        if ($group === null) {
            return true;
        }

        $best = null;

        foreach ($this->groups[$group] as [$allow, $pattern]) {
            if (! self::matches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);

            if ($best === null || $length > $best[0] || ($length === $best[0] && $allow)) {
                $best = [$length, $allow];
            }
        }

        return $best === null || $best[1];
    }

    /**
     * Determine if the crawler's group has rules that keep it out of some paths.
     */
    public function restricts(string $crawler): bool
    {
        $group = $this->groupFor($crawler);

        return $group !== null && array_filter($this->groups[$group], fn (array $rule) => ! $rule[0]) !== [];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = implode('.*', array_map(fn (string $part) => preg_quote($part, '~'), explode('*', rtrim($pattern, '$'))));

        return preg_match('~^'.$regex.($anchored ? '$' : '').'~', $path) === 1;
    }
}
