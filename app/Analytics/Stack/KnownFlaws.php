<?php

namespace App\Analytics\Stack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The known flaws (CVE) of a program's version, read once a day per
 * version; only the product, package and version are sent.
 *
 * A package of the system's own archive (Ubuntu, Debian) is asked of
 * OSV.dev, which holds the distribution's security tracker: the
 * distribution fixes flaws in its own builds without changing the upstream
 * version, so only its tracker knows whether a build still has one, and in
 * which build it was fixed. Any other build (ondrej's PHP, nginx.org,
 * MariaDB's or MySQL's own repositories, built by hand) is asked of the
 * NVD by the upstream version, every flaw found there fixed in a newer
 * release.
 */
final class KnownFlaws
{
    public const string OSV_URL = 'https://api.osv.dev/v1/query';

    public const string NVD_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    /**
     * The NVD names of each product (vendor:product): some go under two.
     *
     * @var array<string, list<string>>
     */
    public const array CPES = [
        'php' => ['php:php'],
        'nginx' => ['f5:nginx', 'nginx:nginx'],
        'apache' => ['apache:http_server'],
        'mysql' => ['oracle:mysql', 'oracle:mysql_server'],
        'mariadb' => ['mariadb:mariadb'],
        'postgresql' => ['postgresql:postgresql'],
        'redis' => ['redis:redis'],
        'memcached' => ['memcached:memcached'],
        'nodejs' => ['nodejs:node.js'],
        'openssl' => ['openssl:openssl'],
    ];

    /**
     * The order of severities, worst last.
     *
     * @var array<string, int>
     */
    public const array SEVERITIES = ['negligible' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /**
     * The most flaws kept for one version, worst first.
     */
    public const int MAX_FLAWS = 200;

    /**
     * Seconds between two NVD requests: 5 in 30 seconds without a key, 50 with one.
     */
    private const float NVD_PAUSE = 6.5;

    private const float NVD_PAUSE_WITH_KEY = 0.7;

    private ?float $lastNvdRequest = null;

    /**
     * The flaws of a distribution's package build, each with the build that
     * fixes it (null while none does); null when they cannot be read.
     *
     * @return ?list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>
     */
    public function ofPackage(string $ecosystem, string $package, string $version): ?array
    {
        return $this->remember("flaws:osv:{$ecosystem}:{$package}:{$version}", fn () => $this->readOsv($ecosystem, $package, $version));
    }

    /**
     * The flaws of an upstream release; null when they cannot be read, or
     * when the NVD does not know the product.
     *
     * @return ?list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>
     */
    public function ofRelease(string $product, string $version): ?array
    {
        if (! isset(self::CPES[$product])) {
            return null;
        }

        return $this->remember("flaws:nvd:{$product}:{$version}", fn () => $this->readNvd($product, $version));
    }

    /**
     * Flaws worst first, the worst of the same severity by score.
     *
     * @param  list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>  $flaws
     * @return list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>
     */
    public static function worstFirst(array $flaws): array
    {
        usort($flaws, fn (array $a, array $b) => [self::SEVERITIES[$b['severity']] ?? -1, $b['score'] ?? 0, $b['id']] <=> [self::SEVERITIES[$a['severity']] ?? -1, $a['score'] ?? 0, $a['id']]);

        return array_slice($flaws, 0, self::MAX_FLAWS);
    }

    /**
     * A read kept a day; one that failed is not kept, and tried again next time.
     *
     * @param  callable(): ?array  $read
     */
    private function remember(string $key, callable $read): ?array
    {
        $flaws = Cache::get($key);

        if (is_array($flaws)) {
            return $flaws;
        }

        $flaws = $read();

        if ($flaws !== null) {
            Cache::put($key, $flaws, now()->addDay());
        }

        return $flaws;
    }

    /**
     * @return ?list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>
     */
    private function readOsv(string $ecosystem, string $package, string $version): ?array
    {
        $flaws = [];
        $pageToken = null;

        for ($page = 0; $page < 10; $page++) {
            try {
                $response = Http::timeout(30)->acceptJson()->post(self::OSV_URL, array_filter([
                    'package' => ['name' => $package, 'ecosystem' => $ecosystem],
                    'version' => $version,
                    'page_token' => $pageToken,
                ]));
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful() || ! is_array($response->json())) {
                return null;
            }

            foreach ($response->json('vulns') ?? [] as $vulnerability) {
                // The tracker's own entry for each CVE; its notices (USN, DSA, DLA) repeat them.
                if (preg_match('/^(?:UBUNTU|DEBIAN)-(CVE-\d+-\d+)$/', (string) ($vulnerability['id'] ?? ''), $match) !== 1) {
                    continue;
                }

                $flaws[$match[1]] = [
                    'id' => $match[1],
                    'severity' => self::osvSeverity($vulnerability),
                    'score' => null,
                    'fixed' => self::osvFixed($vulnerability, $ecosystem, $package),
                ];
            }

            $pageToken = $response->json('next_page_token');

            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        return self::worstFirst(array_values($flaws));
    }

    /**
     * The distribution's priority for the flaw (Ubuntu gives one), if any.
     *
     * @param  array<string, mixed>  $vulnerability
     */
    private static function osvSeverity(array $vulnerability): ?string
    {
        foreach ($vulnerability['severity'] ?? [] as $severity) {
            $score = strtolower((string) ($severity['score'] ?? ''));

            if (in_array($severity['type'] ?? null, ['Ubuntu', 'Debian'], true) && isset(self::SEVERITIES[$score])) {
                return $score;
            }
        }

        return null;
    }

    /**
     * The build of this package, in this release of the distribution, that fixes the flaw.
     *
     * @param  array<string, mixed>  $vulnerability
     */
    private static function osvFixed(array $vulnerability, string $ecosystem, string $package): ?string
    {
        foreach ($vulnerability['affected'] ?? [] as $affected) {
            if (($affected['package']['ecosystem'] ?? null) !== $ecosystem || ($affected['package']['name'] ?? null) !== $package) {
                continue;
            }

            foreach ($affected['ranges'] ?? [] as $range) {
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed']) && is_string($event['fixed'])) {
                        return $event['fixed'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return ?list<array{id: string, severity: ?string, score: ?float, fixed: ?string}>
     */
    private function readNvd(string $product, string $version): ?array
    {
        $flaws = [];

        foreach (self::CPES[$product] as $cpe) {
            $this->waitForNvd();

            try {
                $request = Http::timeout(60)->acceptJson();
                $key = config('services.nvd.key');

                if (is_string($key) && $key !== '') {
                    $request = $request->withHeaders(['apiKey' => $key]);
                }

                $response = $request->get(self::NVD_URL, ['virtualMatchString' => "cpe:2.3:a:{$cpe}:{$version}", 'resultsPerPage' => 2000]);
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful() || ! is_array($response->json('vulnerabilities'))) {
                return null;
            }

            foreach ($response->json('vulnerabilities') as $item) {
                $cve = $item['cve'] ?? [];

                if (! isset($cve['id']) || ($cve['vulnStatus'] ?? null) === 'Rejected') {
                    continue;
                }

                [$severity, $score] = self::nvdSeverity($cve['metrics'] ?? []);
                $flaws[$cve['id']] = ['id' => $cve['id'], 'severity' => $severity, 'score' => $score, 'fixed' => null];
            }
        }

        return self::worstFirst(array_values($flaws));
    }

    /**
     * The flaw's CVSS severity and score: version 3.1 first, then 4.0, 3.0, 2.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{0: ?string, 1: ?float}
     */
    private static function nvdSeverity(array $metrics): array
    {
        foreach (['cvssMetricV31', 'cvssMetricV40', 'cvssMetricV30', 'cvssMetricV2'] as $kind) {
            $metric = $metrics[$kind][0] ?? null;

            if ($metric === null) {
                continue;
            }

            $severity = strtolower((string) ($metric['cvssData']['baseSeverity'] ?? $metric['baseSeverity'] ?? ''));
            $score = $metric['cvssData']['baseScore'] ?? null;

            return [isset(self::SEVERITIES[$severity]) ? $severity : null, is_numeric($score) ? (float) $score : null];
        }

        return [null, null];
    }

    /**
     * Keep to the NVD's rate: a pause since the request before.
     */
    private function waitForNvd(): void
    {
        $pause = filled(config('services.nvd.key')) ? self::NVD_PAUSE_WITH_KEY : self::NVD_PAUSE;

        if ($this->lastNvdRequest !== null) {
            $left = $pause - (microtime(true) - $this->lastNvdRequest);

            if ($left > 0) {
                Sleep::for((int) ceil($left * 1000))->milliseconds();
            }
        }

        $this->lastNvdRequest = microtime(true);
    }
}
