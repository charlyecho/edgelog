<?php

namespace App\Analytics\Security;

use App\Analytics\Classification\Verdict;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\DayFigures;
use App\Analytics\Geo\Places;
use App\Analytics\Patterns;
use App\Analytics\Sources;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;

/**
 * Security, for one day: scanners and what the server answered them, attack
 * attempts and fake browsers. A view across the groups, never a group of its
 * own: every request stays where the classifier put it.
 *
 *  scanner probe    a request of the bots group the classifier marked a probe,
 *                   and a request to a bare IP whose path or query is one
 *  scanner          an address and browser with a probe that day; all its
 *                   requests are counted on its row
 *  forged referrer  a suspect that sent a referrer of the site's own hosts and
 *                   never loaded a file with one: it never drew a page
 *  login hammering  login_posts POSTs to a login or form path from one address
 *                   within login_seconds, people and bots only
 *  burst            burst_requests requests from one address within
 *                   burst_seconds, people and bots only
 *  fake browser     a request the classifier filed as a browser that never
 *                   drew the page, read too fast, or crawled robots.txt
 *
 * "Is anything exposed?": each probe for a file answered 2xx is the host's
 * harmless fallback page (its size within `tolerance` bytes of one that at
 * least `shell_paths` probe paths of the host got that day), empty (0
 * bytes), a possible exposure (any other size), or unknown (no size: never
 * called safe). The log records a size, never the content.
 */
final class SecurityCollector
{
    /**
     * The classifier's reasons that a browser is a fake one.
     */
    public const array FAKE = [VisitEvidence::NOT_RENDERED, VisitEvidence::TOO_FAST, VisitEvidence::CRAWLER];

    /**
     * Every address and browser of the day: its requests, and what makes it a suspect.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pairs = [];

    /**
     * Each host's probes for a file answered 2xx: [path, size, status, time].
     *
     * @var array<string, list<array{0: string, 1: ?int, 2: int, 3: int}>>
     */
    private array $answers = [];

    /**
     * @var array<string, int>
     */
    private array $figures = ['probes' => 0, 'probes_bare_ip' => 0, 'injection' => 0, 'query_only' => 0, 'fake_requests' => 0,
        'gateway' => 0, 'php' => 0, 'posts_sized' => 0, 'large_posts' => 0];

    /**
     * The day's breakdowns: [kind][name] => count.
     *
     * @var array<string, array<string, int>>
     */
    private array $counts = [];

    private readonly Episodes $logins;

    private readonly Episodes $bursts;

    /**
     * @param  list<string>  $ownHosts
     * @param  array<string, int>  $rules  analytics.security
     */
    public function __construct(private readonly array $ownHosts, private readonly array $rules)
    {
        $this->logins = new Episodes($rules['login_posts'], $rules['login_seconds']);
        $this->bursts = new Episodes($rules['burst_requests'], $rules['burst_seconds']);
    }

    /**
     * A request, in time order: with its verdict when it is the site's, none
     * when it went to another host (only a bare IP's are looked at then).
     */
    public function add(LogHit $hit, ?Verdict $verdict): void
    {
        $bareIp = SecurityNames::isBareIp($hit->host);

        if ($verdict === null && ! $bareIp) {
            return;
        }

        $path = $hit->path();
        $time = $hit->when->getTimestamp();
        $answered = intdiv($hit->status, 100).'xx';
        $key = $hit->address."\0".$hit->userAgent;
        $pair = &$this->pairs[$key];
        $pair ??= ['address' => $hit->address, 'user_agent' => substr($hit->userAgent, 0, 255), 'first' => $time, 'requests' => 0, 'probes' => 0, 'categories' => [], 'answered' => [],
            'paths' => [], 'bare_ip' => false, 'own_referrer' => false, 'own_asset' => false, 'fake' => null, 'fake_requests' => 0, 'pages' => []];
        $pair['requests']++;
        $pair['bare_ip'] = $pair['bare_ip'] || $bareIp;

        $ownReferrer = $this->isOwn(Sources::referrerHost($hit->referrer));
        $pair['own_referrer'] = $pair['own_referrer'] || $ownReferrer;
        $pair['own_asset'] = $pair['own_asset'] || ($ownReferrer && preg_match(Patterns::RENDER_ASSET, $path) === 1);

        $isProbe = $verdict === null
            ? self::looksLikeProbe($hit)
            : $verdict->group === TrafficGroup::Bots && $verdict->probe;

        if ($isProbe) {
            $this->probe($hit, $pair, $path, $time, $answered, $bareIp);
        }

        if ($verdict !== null && $verdict->group === TrafficGroup::Bots && in_array($verdict->name, self::FAKE, true)) {
            $this->figures['fake_requests']++;
            $pair['fake'] ??= $verdict->name;
            $pair['fake_requests']++;
            $this->count(CountKind::SecurityFake, $verdict->name);

            if (preg_match(Patterns::ASSET, $path) !== 1) {
                $this->count(CountKind::SecurityFakePage, substr($path, 0, 200) ?: '/');
                $pair['pages'][$path] = ($pair['pages'][$path] ?? 0) + 1;
            }
        }

        if ($verdict === null || in_array($verdict->group, [TrafficGroup::People, TrafficGroup::Bots], true)) {
            $this->bursts->add($hit->address, $time, $path, $answered);

            if ($hit->method === 'POST' && preg_match(SecurityNames::LOGIN_PATH, $path) === 1) {
                $this->logins->add($hit->address, $time, $path, $answered);
            }
        }

        $this->figures['gateway'] += in_array($hit->status, SecurityNames::GATEWAY, true) ? 1 : 0;
        $this->figures['php'] += preg_match(SecurityNames::PHP_PATH, $path) === 1 ? 1 : 0;

        if ($hit->method === 'POST' && $hit->requestLength !== null) {
            $this->figures['posts_sized']++;
            $this->figures['large_posts'] += $hit->requestLength >= $this->rules['large_post_bytes'] ? 1 : 0;
        }

        unset($pair);
    }

    /**
     * Put the day's security into its figures: the counts, each host's
     * answers, the possible exposures, the suspects and the episodes.
     */
    public function finish(DayFigures $figures, ?Places $places = null): void
    {
        foreach ($this->counts as $kind => $names) {
            foreach ($names as $name => $count) {
                $figures->count(CountKind::from($kind), (string) $name, $count);
            }
        }

        [$hosts, $exposures] = $this->exposures();
        $logins = $this->logins->all();
        $bursts = $this->bursts->all();
        $attempts = [];

        foreach ([...$logins, ...$bursts] as $episode) {
            $attempts[$episode['address']] = ($attempts[$episode['address']] ?? 0) + $episode['requests'];
        }

        $suspects = [];
        $scanners = 0;
        $addresses = [];
        $forged = 0;

        foreach ($this->pairs as $pair) {
            $attempted = $attempts[$pair['address']] ?? 0;

            if ($pair['probes'] === 0 && $pair['fake'] === null && $attempted === 0) {
                continue;
            }

            if ($pair['probes'] > 0) {
                $scanners++;
                $addresses[$pair['address']] = true;
            }

            $isForged = $pair['own_referrer'] && ! $pair['own_asset'];
            $forged += $isForged ? 1 : 0;
            unset($attempts[$pair['address']]);

            arsort($pair['paths']);
            arsort($pair['pages']);
            arsort($pair['categories']);

            $suspects[] = [
                'address' => $pair['address'],
                'country' => $places?->of($pair['address'])->country ?? '',
                'user_agent' => $pair['user_agent'],
                'first_seen_at' => $pair['first'],
                'requests' => $pair['requests'],
                'probes' => $pair['probes'],
                'attempts' => $attempted,
                'fake_requests' => $pair['fake_requests'],
                'categories' => $pair['categories'],
                'answered' => $pair['answered'],
                'paths' => array_slice($pair['probes'] > 0 ? $pair['paths'] : $pair['pages'], 0, SecurityNames::ROW_PATHS, true),
                'signs' => array_values(array_filter([
                    $pair['bare_ip'] ? 'bare_ip' : null,
                    $isForged ? 'forged_referrer' : null,
                    $pair['fake'],
                ])),
            ];
        }

        usort($suspects, fn (array $a, array $b) => [$b['probes'], $b['attempts'], $b['requests']] <=> [$a['probes'], $a['attempts'], $a['requests']]);

        $figures->security = [
            'figures' => [
                ...$this->figures,
                'scanners' => $scanners,
                'addresses' => count($addresses),
                'forged_referrers' => $forged,
                'answered_page' => array_sum(array_map('count', $this->answers)),
                ...array_reduce($hosts, fn (array $sum, array $host) => [
                    'shell' => $sum['shell'] + $host['shell'],
                    'empty' => $sum['empty'] + $host['empty'],
                    'possible' => $sum['possible'] + $host['possible'],
                    'unknown' => $sum['unknown'] + $host['unknown'],
                ], ['shell' => 0, 'empty' => 0, 'possible' => 0, 'unknown' => 0]),
                'login_posts' => array_sum(array_column($logins, 'requests')),
                'login_episodes' => count($logins),
                'burst_requests' => array_sum(array_column($bursts, 'requests')),
                'burst_episodes' => count($bursts),
            ],
            'hosts' => $hosts,
            'exposures' => array_slice($exposures, 0, SecurityNames::EXPOSURES),
            'suspects' => array_slice($suspects, 0, SecurityNames::SUSPECTS),
            'episodes' => [
                ...array_map(fn (array $episode) => ['kind' => 'login', ...$episode], array_slice($logins, 0, SecurityNames::EPISODES)),
                ...array_map(fn (array $episode) => ['kind' => 'burst', ...$episode], array_slice($bursts, 0, SecurityNames::EPISODES)),
            ],
        ];
    }

    /**
     * Determine if a request to another host is a probe: by its path or its query alone.
     */
    public static function looksLikeProbe(LogHit $hit): bool
    {
        return preg_match(Patterns::SECRET_PROBE, $hit->path()) === 1
            || preg_match(Patterns::SERVED_PROBE, $hit->path()) === 1
            || preg_match(Patterns::PROBE_QUERY, $hit->url) === 1;
    }

    private function count(CountKind $kind, string $name, int $by = 1): void
    {
        $this->counts[$kind->value][$name] = ($this->counts[$kind->value][$name] ?? 0) + $by;
    }

    /**
     * @param  array<string, mixed>  $pair
     */
    private function probe(LogHit $hit, array &$pair, string $path, int $time, string $answered, bool $bareIp): void
    {
        $category = SecurityNames::category($path, $hit->url);
        $this->figures['probes']++;
        $this->figures['probes_bare_ip'] += $bareIp ? 1 : 0;
        $this->count(CountKind::SecurityCategory, $category);
        $this->count(CountKind::SecurityAnswer, $answered);
        $this->count(CountKind::SecurityPath, substr($path, 0, 200) ?: '/');
        $this->count(CountKind::SecurityPathAnswer, (substr($path, 0, 200) ?: '/').SecurityNames::ANSWER_SEPARATOR.$answered);

        $pair['probes']++;
        $pair['categories'][$category] = ($pair['categories'][$category] ?? 0) + 1;
        $pair['answered'][$answered] = ($pair['answered'][$answered] ?? 0) + 1;
        $pair['paths'][$path] = ($pair['paths'][$path] ?? 0) + 1;

        if (preg_match(Patterns::PROBE_QUERY, $hit->url) === 1) {
            $this->figures['injection']++;
            $this->count(CountKind::SecurityInjection, SecurityNames::injection($hit->url));
        }

        if ($hit->status < 200 || $hit->status >= 300) {
            return;
        }

        if (preg_match(Patterns::SECRET_PROBE, $path) !== 1 && preg_match(Patterns::SERVED_PROBE, $path) !== 1) {
            $this->figures['query_only']++;

            return;
        }

        $this->answers[strtolower($hit->host)][] = [substr($path, 0, 200), $hit->size, $hit->status, $time];
    }

    /**
     * Each host's verdicts, and the answers worth a look: possible exposures and unknown sizes.
     *
     * @return array{0: array<string, array{bare_ip: bool, answered: int, shells: list<int>, shell: int, empty: int, possible: int, unknown: int}>, 1: list<array{host: string, path: string, status: int, size: ?int, at: int, verdict: string}>}
     */
    private function exposures(): array
    {
        $hosts = [];
        $exposures = [];
        $tolerance = $this->rules['tolerance'];

        foreach ($this->answers as $host => $answers) {
            $pathsBySize = [];

            foreach ($answers as [$path, $size]) {
                if ($size !== null && $size > 0) {
                    $pathsBySize[$size][$path] = true;
                }
            }

            $shells = [];

            foreach (array_keys($pathsBySize) as $size) {
                $paths = [];

                foreach ($pathsBySize as $other => $at) {
                    if (abs($other - $size) <= $tolerance) {
                        $paths += $at;
                    }
                }

                if (count($paths) >= $this->rules['shell_paths']) {
                    $shells[] = $size;
                }
            }

            $row = ['bare_ip' => SecurityNames::isBareIp((string) $host), 'answered' => count($answers), 'shells' => [], 'shell' => 0, 'empty' => 0, 'possible' => 0, 'unknown' => 0];

            foreach ($answers as [$path, $size, $status, $time]) {
                $verdict = match (true) {
                    $size === null => 'unknown',
                    $size === 0 => 'empty',
                    array_filter($shells, fn (int $shell) => abs($shell - $size) <= $tolerance) !== [] => 'shell',
                    default => 'possible',
                };
                $row[$verdict]++;

                if ($verdict === 'possible' || $verdict === 'unknown') {
                    $exposures[] = ['host' => (string) $host, 'path' => $path, 'status' => $status, 'size' => $size, 'at' => $time, 'verdict' => $verdict];
                }
            }

            sort($shells);
            $row['shells'] = $shells;
            $hosts[(string) $host] = $row;
        }

        usort($exposures, fn (array $a, array $b) => [$a['verdict'] === 'possible' ? 0 : 1, $a['at']] <=> [$b['verdict'] === 'possible' ? 0 : 1, $b['at']]);

        return [$hosts, $exposures];
    }

    /**
     * A host of the site, or a subdomain of one (with "www." taken off).
     */
    private function isOwn(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        foreach ($this->ownHosts as $own) {
            $base = str_starts_with($own, 'www.') ? substr($own, 4) : $own;

            if ($host === $own || $host === $base || str_ends_with($host, ".{$base}")) {
                return true;
            }
        }

        return false;
    }
}
