<?php

namespace App\Analytics\Audit;

use App\Analytics\DayWindow;
use App\Analytics\Patterns;
use DateTimeImmutable;
use DateTimeZone;

/**
 * An independent recount of a day, from the raw log lines.
 *
 * The day's checks catch figures that disagree with each other; they cannot
 * catch a number that is wrong everywhere at once (a visit counted twice, a
 * rule applied in the wrong order). So this counts the day again by another
 * road: nothing here calls the main count's reader, parser, classifier,
 * page rule, source rule or visit counter. It has its own line patterns, its
 * own rule order, its own visit cuts. It shares only the name lists
 * (Patterns), because they are data: a recount with another list would be
 * counting something else.
 *
 * When the two roads disagree, one of two pieces of code written apart is
 * wrong, and the day is withheld until somebody finds out which.
 */
final class Recount
{
    private const string Q = '((?:[^"\\\\]|\\\\.)*)';

    private const array GROUPS = ['people', 'answer_engines', 'search_engines', 'bots', 'ours'];

    /**
     * @param  list<string>  $ownHosts
     */
    public function __construct(
        private readonly string $folder,
        private readonly string $format,
        private readonly DayWindow $window,
        private readonly array $ownHosts,
        private readonly bool $requireRendering = true,
        private readonly int $gap = 1800,
        private readonly int $tooFastPages = 6,
        private readonly int $tooFastSeconds = 2,
    ) {}

    /**
     * The day's core figures, keyed as DayFigures::core() keys them.
     *
     * @return array<string, int>
     */
    public function figures(): array
    {
        $out = ['parsed' => 0, 'hits' => 0, 'other_hosts' => 0, 'cited_visits' => 0, 'tier.training' => 0, 'tier.asked' => 0,
            'users.signed_in' => 0, 'people.signed_in_readers' => 0];

        foreach (self::GROUPS as $group) {
            foreach (['requests', 'views', 'visits', 'readers', 'probes'] as $figure) {
                $out["{$group}.{$figure}"] = 0;
            }
        }

        $lines = [];
        $start = $this->window->start->getTimestamp();
        $end = $this->window->end->getTimestamp();
        $placing = false;

        foreach ($this->rawLines() as $n => $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $line = $this->read($raw);

            if ($line === null) {
                $out['parsed'] += $placing ? 1 : 0;

                continue;
            }

            $placing = $line['t'] >= $start && $line['t'] < $end;

            if (! $placing) {
                continue;
            }

            $out['parsed']++;

            if ($line['h'] !== '' && ! in_array($line['h'], $this->ownHosts, true)) {
                $out['other_hosts']++;

                continue;
            }

            $line['n'] = $n;
            $lines[] = $line;
        }

        usort($lines, fn (array $a, array $b) => [$a['t'], $a['n']] <=> [$b['t'], $b['n']]);

        $scanners = $this->scanners($lines);
        $flagged = $this->flaggedVisits($lines);
        $open = [];
        $users = [];
        $signedVisits = [];
        $readVisits = [];
        $visitNumber = 0;

        foreach ($lines as $line) {
            $out['hits']++;
            [$group, $tier, $probe, $cited] = $this->judge($line, $scanners, $flagged);
            $page = $this->page($line);

            $out["{$group}.requests"]++;
            $out["{$group}.views"] += $page ? 1 : 0;
            $out["{$group}.probes"] += $probe ? 1 : 0;

            if ($tier !== '') {
                $out["tier.{$tier}"]++;
            }

            $origin = null;
            $outside = false;

            if ($page) {
                if ($group === 'people') {
                    $source = $this->source($line['r'], $line['u'], $cited);
                    $origin = $source.'|'.$this->referrerOf($line['r']).'|'.$this->campaignOf($line['u']);
                    $outside = $source !== 'This site' && $source !== 'Direct or unknown';
                } else {
                    $origin = '-';
                }
            }

            $id = $group."\n".$line['a']."\n".$line['ua'];
            $visit = $open[$id] ?? null;

            if ($visit === null || $this->gap < $line['t'] - $visit[0]
                || ($outside && $visit[1] !== null && $visit[1] !== $origin)) {
                $out["{$group}.visits"]++;
                $visit = [$line['t'], null, ++$visitNumber];
            }

            $visit[0] = $line['t'];

            if ($origin !== null && $visit[1] === null) {
                $visit[1] = $origin;
                $out["{$group}.readers"]++;

                if ($group === 'people' && $cited) {
                    $out['cited_visits']++;
                }

                if ($group === 'people') {
                    $readVisits[$visit[2]] = true;
                }
            }

            if ($group === 'people' && $line['uid'] !== '') {
                $users[$line['uid']] = true;
                $signedVisits[$visit[2]] = true;
            }

            $open[$id] = $visit;
        }

        $out['users.signed_in'] = count($users);
        $out['people.signed_in_readers'] = count(array_intersect_key($signedVisits, $readVisits));

        return $out;
    }

    // ------------------------------------------------------------ lines

    /**
     * Every line of the files that may hold the window, oldest file first.
     *
     * @return iterable<int, string>
     */
    private function rawLines(): iterable
    {
        $names = [];

        foreach (scandir($this->folder) ?: [] as $name) {
            if ($name === 'access.log') {
                $names[0] = $name;
            } elseif (preg_match('/^access\.log\.(\d+)(\.gz)?$/', $name, $m) === 1) {
                $names[(int) $m[1]] = $name;
            }
        }

        krsort($names);
        $n = 0;

        foreach ($names as $name) {
            $path = "{$this->folder}/{$name}";

            if (filemtime($path) < $this->window->start->getTimestamp()) {
                continue;
            }

            $handle = str_ends_with($name, '.gz') ? gzopen($path, 'rb') : fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            while (! (str_ends_with($name, '.gz') ? gzeof($handle) : feof($handle))) {
                $raw = str_ends_with($name, '.gz') ? gzgets($handle) : fgets($handle);

                if ($raw !== false) {
                    yield $n++ => $raw;
                }
            }

            str_ends_with($name, '.gz') ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * @return ?array{t: int, a: string, h: string, m: string, u: string, p: string, s: int, r: string, ua: string, pf: ?string}
     */
    private function read(string $raw): ?array
    {
        $raw = rtrim($raw, "\r\n");
        $formats = match ($this->format) {
            'nginx_extended' => ['extended'],
            'nginx_combined', 'apache_combined' => ['combined'],
            default => ['extended', 'combined'],
        };

        foreach ($formats as $format) {
            $line = $format === 'extended' ? $this->readExtended($raw) : $this->readCombined($raw);

            if ($line !== null) {
                return $line;
            }
        }

        return null;
    }

    private function readCombined(string $raw): ?array
    {
        if (preg_match('~^(\S+) \S+ \S+ \[([^\]]+)\] "'.self::Q.'" (\d{3}) \S+ "'.self::Q.'" "'.self::Q.'"(.*)$~s', $raw, $m) !== 1) {
            return null;
        }

        $when = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[2]);

        if ($when === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        $host = preg_match('~\s"((?=[^"]*[A-Za-z])[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)(?::\d+)?"\s*$~', $this->withoutFields($m[7]), $h) === 1 ? strtolower($h[1]) : '';

        return $this->line($when, $m[1], $host, $m[3], (int) $m[4], $m[5], $m[6], $m[7]);
    }

    private function readExtended(string $raw): ?array
    {
        if (preg_match('~^(\S+) (\S+) (\S+) "'.self::Q.'" (\d{3}) \S+ "'.self::Q.'" "'.self::Q.'"(.*)$~s', $raw, $m) !== 1) {
            return null;
        }

        $when = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $m[1]);

        if ($when === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        $host = $m[3] === '-' ? '' : strtolower(stripcslashes($m[3]));

        return $this->line($when, $m[2], $host, $m[4], (int) $m[5], $m[6], $m[7], $m[8]);
    }

    private function line(DateTimeImmutable $when, string $address, string $host, string $request, int $status, string $referrer, string $agent, string $tail): array
    {
        $method = '';
        $url = '';

        if (preg_match('~^([A-Z]+) (\S*)~', $request, $r) === 1) {
            [, $method, $url] = $r;
        }

        $prefetch = null;

        if (preg_match('~(?:^|\s)rsc_prefetch=(\S*)~', $tail, $f) === 1) {
            $prefetch = $f[1];
        }

        $user = '';

        if (preg_match('~(?:^|\s)uid=([A-Za-z0-9_.:-]{1,128})(?=\s|$)~', $tail, $u) === 1 && $u[1] !== '-') {
            $user = $u[1];
        }

        $query = strpos($url, '?');

        return [
            't' => $when->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
            'a' => $address,
            'h' => $host,
            'm' => $method,
            'u' => $url,
            'p' => $query === false ? $url : substr($url, 0, $query),
            's' => $status,
            'r' => $referrer === '-' ? '' : stripcslashes($referrer),
            'ua' => $agent === '-' ? '' : stripcslashes($agent),
            'pf' => $prefetch,
            'uid' => $user,
        ];
    }

    private function withoutFields(string $tail): string
    {
        return (string) preg_replace('~(?:^|\s)(?:rsc_prefetch|request_length|uid)=\S*~', '', $tail);
    }

    // ------------------------------------------------------- what it is

    private function page(array $line): bool
    {
        if ($line['m'] !== 'GET' && $line['m'] !== 'HEAD') {
            return false;
        }

        if ($line['s'] !== 304 && intdiv($line['s'], 100) !== 2) {
            return false;
        }

        if (preg_match(Patterns::ASSET, $line['p']) || preg_match(Patterns::BEACON, $line['p'])) {
            return false;
        }

        if (preg_match(Patterns::RSC_PAYLOAD, $line['u'])) {
            return $line['m'] === 'GET' && preg_match(Patterns::RSC_QUERY, $line['u'])
                && ($line['pf'] === '-' || $line['pf'] === '');
        }

        return true;
    }

    /**
     * [group, tier, probe, cited]
     *
     * @param  array<string, bool>  $scanners
     * @param  array<string, list<array{0: int, 1: int}>>  $flagged
     * @return array{0: string, 1: string, 2: bool, 3: bool}
     */
    private function judge(array $line, array $scanners, array $flagged): array
    {
        $ua = $line['ua'];
        $probe = (bool) (preg_match(Patterns::SECRET_PROBE, $line['p']) || preg_match(Patterns::SERVED_PROBE, $line['p'])
            || preg_match(Patterns::PROBE_QUERY, $line['u']));
        $id = $line['a']."\n".$ua;

        $named = [
            [Patterns::AI_ASKED, 'answer_engines', 'asked'],
            [Patterns::AI_TRAINING, 'answer_engines', 'training'],
        ];

        foreach ($named as [$pattern, $group, $tier]) {
            if (preg_match($pattern, $ua)) {
                return [$group, $tier, $probe, false];
            }
        }

        foreach (Patterns::LINK_PREVIEWS as $preview) {
            if (preg_match($preview[0], $ua)) {
                return ['bots', '', $probe, false];
            }
        }

        if (preg_match(Patterns::SEARCH_ENGINE, $ua)) {
            return ['search_engines', '', $probe, false];
        }

        $automated = $probe || isset($scanners[$id]) || $ua === '' || preg_match(Patterns::OTHER_BOT, $ua)
            || (preg_match(Patterns::COMPATIBLE, $ua) && ! preg_match(Patterns::ENGINE, $ua))
            || preg_match(Patterns::MACHINE_PATH, $line['p']);

        if ($automated || ! preg_match(Patterns::BROWSER, $ua)) {
            return ['bots', '', $probe, false];
        }

        foreach ($flagged[$id] ?? [] as [$from, $to]) {
            if ($line['t'] >= $from && $line['t'] <= $to) {
                return ['bots', '', false, false];
            }
        }

        return ['people', '', false, $this->cited($line['r'], $line['u'])];
    }

    /**
     * Every address and agent that asked for a secret or sent an attack.
     *
     * @return array<string, bool>
     */
    private function scanners(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            if (preg_match(Patterns::SECRET_PROBE, $line['p']) || preg_match(Patterns::PROBE_QUERY, $line['u'])) {
                $found[$line['a']."\n".$line['ua']] = true;
            }
        }

        return $found;
    }

    /**
     * The browser-like visits that are no person: a crawler's, one that
     * never drew a page, one too fast. As [first, last] per visitor.
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function flaggedVisits(array $lines): array
    {
        $byVisitor = [];

        foreach ($lines as $line) {
            if (preg_match(Patterns::BROWSER, $line['ua'])) {
                $byVisitor[$line['a']."\n".$line['ua']][] = $line;
            }
        }

        $flagged = [];

        foreach ($byVisitor as $id => $own) {
            $drewBefore = false;
            $visit = [];
            $own[] = null;

            foreach ($own as $line) {
                if ($visit !== [] && ($line === null || $this->gap < $line['t'] - $visit[count($visit) - 1]['t'])) {
                    $pageTimes = [];
                    $drawTimes = [];
                    $ownAssets = 0;
                    $robots = false;

                    foreach ($visit as $v) {
                        if ($this->page($v)) {
                            $pageTimes[] = $v['t'];
                        }

                        $referrerHost = $v['r'] === '' ? '' : strtolower((string) parse_url($v['r'], PHP_URL_HOST));
                        $ownFile = (preg_match(Patterns::ASSET, $v['p']) || preg_match(Patterns::RSC_PAYLOAD, $v['u']))
                            && $referrerHost !== '' && in_array($referrerHost, $this->ownHosts, true);

                        if ($ownFile) {
                            $ownAssets++;
                        }

                        if ($ownFile || preg_match(Patterns::RENDER_ASSET, $v['p']) || preg_match(Patterns::RSC_PAYLOAD, $v['u'])) {
                            $drawTimes[] = $v['t'];
                        }

                        $robots = $robots || preg_match(Patterns::CRAWLER_PATH, $v['p']);
                    }

                    $drew = false;

                    foreach ($pageTimes as $p) {
                        foreach ($drawTimes as $d) {
                            $drew = $drew || ($d - $p >= -5 && $d - $p <= 30);
                        }
                    }

                    $bad = ($robots && ($ownAssets === 0 || $ownAssets < count($pageTimes)))
                        || ($this->requireRendering && $pageTimes !== [] && ! $drew && ! $drewBefore)
                        || ($this->tooFastPages > 0 && count($pageTimes) >= $this->tooFastPages
                            && max($pageTimes) - min($pageTimes) <= $this->tooFastSeconds);

                    if ($bad) {
                        $flagged[$id][] = [$visit[0]['t'], $visit[count($visit) - 1]['t']];
                    }

                    $drewBefore = $drewBefore || $drew;
                    $visit = [];
                }

                if ($line !== null) {
                    $visit[] = $line;
                }
            }
        }

        return $flagged;
    }

    // ---------------------------------------------------------- sources

    private function hostOf(string $referrer): string
    {
        $host = strtolower(rtrim((string) parse_url(str_contains($referrer, '//') ? $referrer : '//'.$referrer, PHP_URL_HOST), '.'));

        return preg_replace('~^www\.~', '', $host) ?? $host;
    }

    private function cited(string $referrer, string $url): bool
    {
        if ($referrer !== '') {
            $host = strtolower(rtrim((string) parse_url(str_contains($referrer, '//') ? $referrer : '//'.$referrer, PHP_URL_HOST), '.'));
            $path = (string) parse_url(str_contains($referrer, '//') ? $referrer : '//'.$referrer, PHP_URL_PATH);

            foreach ([...Patterns::AI_REFERRER_HOSTS, ...array_keys(Patterns::AI_REFERRER_PATHS)] as $domain) {
                $matches = $host !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
                $prefix = Patterns::AI_REFERRER_PATHS[$domain] ?? null;

                if ($matches && ($prefix === null || str_starts_with($path, $prefix))) {
                    return true;
                }
            }
        }

        return (bool) preg_match(Patterns::AI_UTM, $url);
    }

    private function own(string $host): bool
    {
        return in_array($host, $this->ownHosts, true) || in_array('www.'.$host, $this->ownHosts, true);
    }

    private function source(string $referrer, string $url, bool $cited): string
    {
        if ($cited) {
            return 'AI assistant';
        }

        $host = $referrer === '' ? '' : $this->hostOf($referrer);

        if ($host !== '') {
            if ($this->own($host)) {
                return 'This site';
            }

            foreach (['Email' => Patterns::EMAIL_HOST, 'Search engine' => Patterns::SEARCH_HOST, 'Social media' => Patterns::SOCIAL_HOST] as $type => $pattern) {
                if (preg_match($pattern, $host)) {
                    return $type;
                }
            }

            return 'Other website';
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query = array_change_key_case(array_filter($query, 'is_string'));

        if (($query['utm_source'] ?? '') !== '' || ($query['utm_medium'] ?? '') !== '') {
            foreach (['utm_source', 'utm_medium'] as $tag) {
                $words = preg_split('~[^a-z0-9]+~', strtolower($query[$tag] ?? '')) ?: [];

                foreach (Patterns::UTM_WORDS as $type => $keys) {
                    if (array_intersect($words, $keys)) {
                        return $type;
                    }
                }
            }

            return 'Tagged link';
        }

        foreach (Patterns::CLICK_IDS as $id => $type) {
            if (($query[$id] ?? '') !== '') {
                return $type;
            }
        }

        return 'Direct or unknown';
    }

    private function referrerOf(string $referrer): string
    {
        $host = $referrer === '' ? '' : $this->hostOf($referrer);

        return $host === '' ? '(none)' : ($this->own($host) ? '(this site)' : substr($host, 0, 60));
    }

    private function campaignOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $query = array_change_key_case(array_filter($query, 'is_string'));
        $parts = [$query['utm_source'] ?? '', $query['utm_medium'] ?? '', $query['utm_campaign'] ?? ''];

        if (implode('', $parts) === '') {
            return '(no campaign tag)';
        }

        return substr(implode(' / ', array_map(fn (string $p) => $p === '' ? '-' : strtolower($p), $parts)), 0, 120);
    }
}
