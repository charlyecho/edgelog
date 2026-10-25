<?php

namespace App\Analytics\Stack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Each product's branches from endoflife.date: the newest release of each,
 * and when its support ends. Read once a day per product; only the
 * product's name is sent.
 *
 * A release is "current" when it is the newest of its branch, "update"
 * when a newer one is out, "eol" when its branch is no longer supported,
 * "check_version" when the server does not show it, and "not_listed" when
 * endoflife.date does not know its branch.
 */
final class EndOfLife
{
    public const string URL = 'https://endoflife.date/api/%s.json';

    /**
     * The products endoflife.date knows, by Edgelog's names.
     *
     * @var array<string, string>
     */
    public const array PRODUCTS = [
        'php' => 'php', 'nginx' => 'nginx', 'apache' => 'apache-http-server', 'caddy' => 'caddy', 'nodejs' => 'nodejs',
        'nextjs' => 'nextjs', 'wordpress' => 'wordpress', 'drupal' => 'drupal', 'joomla' => 'joomla', 'laravel' => 'laravel',
        'symfony' => 'symfony', 'django' => 'django', 'rails' => 'rails', 'magento' => 'magento',
    ];

    /**
     * The release's status, its branch, the newest release of its branch,
     * when the branch's support ends, and the newest supported branch.
     *
     * @return array{status: string, cycle: ?string, latest: ?string, eol: ?string, newest: ?string}
     */
    public function judge(string $product, ?string $version): array
    {
        $none = ['status' => 'check_version', 'cycle' => null, 'latest' => null, 'eol' => null, 'newest' => null];
        $cycles = isset(self::PRODUCTS[$product]) ? $this->cycles(self::PRODUCTS[$product]) : null;

        if ($cycles === null) {
            return [...$none, 'status' => $version === null ? 'check_version' : 'not_listed'];
        }

        $newest = collect($cycles)->first(fn (array $cycle) => ! self::isOver($cycle['eol'] ?? false))['latest'] ?? null;

        if ($version === null) {
            return [...$none, 'newest' => $newest];
        }

        foreach ($cycles as $cycle) {
            $branch = (string) $cycle['cycle'];

            if ($version !== $branch && ! str_starts_with($version, "{$branch}.")) {
                continue;
            }

            $latest = (string) ($cycle['latest'] ?? '');
            $eol = is_string($cycle['eol'] ?? null) ? $cycle['eol'] : null;

            return [
                'status' => match (true) {
                    self::isOver($cycle['eol'] ?? false) => 'eol',
                    $latest !== '' && version_compare($version, $latest, '<') => 'update',
                    default => 'current',
                },
                'cycle' => $branch,
                'latest' => $latest ?: null,
                'eol' => $eol,
                'newest' => $newest,
            ];
        }

        return [...$none, 'status' => 'not_listed', 'newest' => $newest];
    }

    /**
     * @return ?list<array<string, mixed>>
     */
    private function cycles(string $slug): ?array
    {
        return Cache::remember("endoflife:{$slug}", now()->addDay(), function () use ($slug) {
            try {
                $response = Http::timeout(10)->acceptJson()->get(sprintf(self::URL, $slug));

                return $response->successful() && is_array($response->json()) ? $response->json() : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    private static function isOver(mixed $eol): bool
    {
        return $eol === true || (is_string($eol) && $eol <= now()->toDateString());
    }
}
