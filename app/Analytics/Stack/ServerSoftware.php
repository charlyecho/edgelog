<?php

namespace App\Analytics\Stack;

use App\Analytics\SiteAlerts;

/**
 * What runs on a server, as edgelog-ship --metrics says it once an hour:
 * its system and its state (a reboot waited for, the updates waiting, the
 * addresses listened on, sshd's settings, the failed services), and each
 * program with the Debian package it comes from. Each program is judged:
 *
 *   * a build of the system's own archive ("distro") lives as long as the
 *     system does, and its flaws are those the distribution's tracker still
 *     lists for that build, each with the build that fixes it;
 *   * any other build ("upstream") is set against its branch on
 *     endoflife.date, and its flaws are those the NVD lists for its version.
 *
 * The kernel's flaws are not read (a thousand a release): the system's
 * security updates and the reboot they wait for tell what to do about them.
 *
 * @phpstan-type Flaw array{id: string, severity: ?string, score: ?float, fixed: ?string}
 * @phpstan-type Program array{product: string, version: string, package: ?string, package_version: ?string, settings?: ?array<string, string>, source?: string, status?: string, cycle?: ?string, latest?: ?string, eol?: ?string, newest?: ?string, flaws?: ?list<Flaw>}
 * @phpstan-type SystemState array{reboot_required: ?bool, reboot_packages: list<string>, updates: ?int, security_updates: ?int, unattended_upgrades: ?bool, listening: ?list<string>, ssh: ?array{permit_root_login: ?string, password_authentication: ?string, partial: bool}, failed_units: ?list<string>}
 * @phpstan-type Software array{os: ?string, os_version: ?string, os_status?: ?array{status: string, cycle: ?string, latest: ?string, eol: ?string, newest: ?string}, programs: list<Program>, system?: ?SystemState}
 */
final class ServerSoftware
{
    /**
     * The programs read, by the shipper's names, for people.
     *
     * @var array<string, string>
     */
    public const array PRODUCTS = [
        'php' => 'PHP', 'nginx' => 'nginx', 'apache' => 'Apache', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB',
        'postgresql' => 'PostgreSQL', 'redis' => 'Redis', 'memcached' => 'Memcached', 'nodejs' => 'Node.js',
        'openssl' => 'OpenSSL', 'linux' => 'Linux kernel',
    ];

    /**
     * The programs whose flaws are not read.
     *
     * @var list<string>
     */
    public const array FLAWS_NOT_READ = ['linux'];

    /**
     * The systems whose archive Edgelog knows, for people.
     *
     * @var array<string, string>
     */
    public const array SYSTEMS = ['ubuntu' => 'Ubuntu', 'debian' => 'Debian'];

    /**
     * The PHP settings the shipper reads from FPM.
     *
     * @var list<string>
     */
    public const array PHP_SETTINGS = ['display_errors', 'expose_php', 'opcache.enable'];

    /**
     * Services that should not answer the internet, by their usual TCP port.
     *
     * @var array<int, string>
     */
    public const array PRIVATE_SERVICES = [
        3306 => 'MySQL / MariaDB', 5432 => 'PostgreSQL', 6379 => 'Redis', 11211 => 'Memcached', 27017 => 'MongoDB',
        9200 => 'Elasticsearch', 9000 => 'PHP-FPM', 2375 => 'Docker', 5672 => 'RabbitMQ', 5984 => 'CouchDB',
    ];

    /**
     * Package builds that come from outside the system's archive: ondrej's
     * PHP (deb.sury.org), nginx.org and redis.io (~jammy), MariaDB's
     * repositories (+maria~), MySQL's (-1ubuntu22.04, -1debian12),
     * NodeSource's, PostgreSQL's (pgdg).
     */
    private const string OUTSIDE_BUILD = '/sury|\+maria|nodesource|pgdg|~[a-z]+\d*$|\dubuntu\d\d\.\d\d$|\ddebian\d+$/i';

    /**
     * What the shipper sent, kept to the programs Edgelog knows, each once, in
     * order (FPM's settings on the command line's build of the same version),
     * and the machine's state, each value of the type it should be.
     *
     * @param  array<string, mixed>  $sent
     * @return Software
     */
    public static function reported(array $sent): array
    {
        $programs = [];

        foreach ($sent['programs'] ?? [] as $program) {
            if (! is_array($program) || ! isset(self::PRODUCTS[$program['product'] ?? '']) || self::upstreamVersion((string) ($program['version'] ?? '')) === null) {
                continue;
            }

            $row = [
                'product' => $program['product'],
                'version' => (string) $program['version'],
                'package' => self::text($program['package'] ?? null),
                'package_version' => self::text($program['package_version'] ?? null),
            ];
            $key = implode("\t", $row);
            $settings = self::phpSettings($program['settings'] ?? null);
            $programs[$key] = [...$row, 'settings' => ($programs[$key]['settings'] ?? null) ?? $settings];
        }

        ksort($programs);

        return [
            'os' => self::text($sent['os'] ?? null),
            'os_version' => self::text($sent['os_version'] ?? null),
            'programs' => array_values($programs),
            'system' => is_array($sent['system'] ?? null) ? self::systemState($sent['system']) : null,
        ];
    }

    /**
     * Whether the shipper says other programs run on the machine than those
     * kept: the system's state and the settings change without a new judgement.
     *
     * @param  ?Software  $kept
     * @param  Software  $reported
     */
    public static function changed(?array $kept, array $reported): bool
    {
        if ($kept === null) {
            return true;
        }

        $plain = fn (array $software) => [
            $software['os'] ?? null,
            $software['os_version'] ?? null,
            array_map(self::identity(...), $software['programs'] ?? []),
        ];

        return $plain($kept) !== $plain($reported);
    }

    /**
     * What the shipper sent, with what was judged before of the same system
     * and the same builds, until they are judged again.
     *
     * @param  ?Software  $kept
     * @param  Software  $reported
     * @return Software
     */
    public static function withJudgements(?array $kept, array $reported): array
    {
        if ($kept === null) {
            return $reported;
        }

        $judged = collect($kept['programs'] ?? [])->keyBy(fn (array $program) => implode("\t", self::identity($program)));

        foreach ($reported['programs'] as $index => $program) {
            $before = $judged->get(implode("\t", self::identity($program)));

            if ($before !== null) {
                $reported['programs'][$index] = [...$program, ...array_intersect_key($before, array_flip(['source', 'status', 'cycle', 'latest', 'eol', 'newest', 'flaws']))];
            }
        }

        if (($kept['os'] ?? null) === $reported['os'] && ($kept['os_version'] ?? null) === $reported['os_version']) {
            $reported['os_status'] = $kept['os_status'] ?? null;
        }

        return $reported;
    }

    /**
     * The release a program says it is: its leading x.y or x.y.z.
     */
    public static function upstreamVersion(string $version): ?string
    {
        return preg_match('/^\d+\.\d+(?:\.\d+)?/', $version, $match) === 1 ? $match[0] : null;
    }

    /**
     * The name OSV.dev knows the system's archive by: Ubuntu:22.04:LTS, Debian:12.
     */
    public static function ecosystem(?string $os, ?string $osVersion): ?string
    {
        return match (true) {
            $osVersion === null => null,
            $os === 'ubuntu' && preg_match('/^(\d\d)\.(\d\d)$/', $osVersion, $match) === 1 => "Ubuntu:{$osVersion}".($match[2] === '04' && (int) $match[1] % 2 === 0 ? ':LTS' : ''),
            $os === 'debian' && preg_match('/^(\d+)/', $osVersion, $match) === 1 => "Debian:{$match[1]}",
            default => null,
        };
    }

    /**
     * Whether the program is a build of the system's own archive.
     *
     * @param  Program  $program
     */
    public static function isDistroBuild(array $program, ?string $os): bool
    {
        return isset(self::SYSTEMS[$os ?? ''])
            && $program['package'] !== null
            && $program['package_version'] !== null
            && preg_match(self::OUTSIDE_BUILD, $program['package_version']) !== 1;
    }

    /**
     * Every program judged against its end of life and its known flaws. A
     * read that failed keeps what the check before found for the same build.
     *
     * @param  Software  $software
     * @return Software
     */
    public static function judge(array $software, EndOfLife $endOfLife, KnownFlaws $knownFlaws): array
    {
        $os = $software['os'];
        $ecosystem = self::ecosystem($os, $software['os_version']);
        $software['os_status'] = isset(self::SYSTEMS[$os ?? '']) && $software['os_version'] !== null
            ? self::systemStatus($endOfLife->judge($os, $software['os_version']))
            : null;

        foreach ($software['programs'] as $index => $program) {
            $version = (string) self::upstreamVersion($program['version']);
            $readFlaws = ! in_array($program['product'], self::FLAWS_NOT_READ, true);

            if ($ecosystem !== null && self::isDistroBuild($program, $os)) {
                $flaws = $readFlaws ? $knownFlaws->ofPackage($ecosystem, $program['package'], $program['package_version']) : null;
                $judged = ['source' => 'distro', 'status' => 'distro', 'cycle' => null, 'latest' => null, 'eol' => null, 'newest' => null];
            } else {
                $flaws = $readFlaws ? $knownFlaws->ofRelease($program['product'], $version) : null;
                $judged = ['source' => 'upstream', ...$endOfLife->judge($program['product'], $version)];
            }

            $software['programs'][$index] = [...$program, ...$judged, 'flaws' => $readFlaws ? ($flaws ?? ($program['flaws'] ?? null)) : null];
        }

        return $software;
    }

    /**
     * The flaws a program still has that an update fixes: for a distribution
     * build those it has a fixed build of, for any other all of them (a newer
     * release fixes them).
     *
     * @param  Program  $program
     * @return list<Flaw>
     */
    public static function fixable(array $program): array
    {
        return array_values(array_filter($program['flaws'] ?? [], fn (array $flaw) => ($program['source'] ?? null) !== 'distro' || $flaw['fixed'] !== null));
    }

    /**
     * Whether a flaw is worth an alert: medium, high, critical, or not rated.
     *
     * @param  Flaw  $flaw
     */
    public static function isSerious(array $flaw): bool
    {
        return ($flaw['severity'] ?? null) === null || KnownFlaws::SEVERITIES[$flaw['severity']] >= KnownFlaws::SEVERITIES['medium'];
    }

    /**
     * The program's name and version, for people: "PHP 8.3.13".
     *
     * @param  Program  $program
     */
    public static function label(array $program): string
    {
        return (self::PRODUCTS[$program['product']] ?? $program['product']).' '.(self::upstreamVersion($program['version']) ?? $program['version']);
    }

    /**
     * Whether a PHP setting is on, the way php.ini spells it.
     */
    public static function isOn(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', '1', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    /**
     * The addresses that answer from outside the machine for a service that
     * should not: every interface (0.0.0.0, ::, *) or a public address.
     *
     * @param  ?SystemState  $system
     * @return list<array{service: string, port: int, address: string}>
     */
    public static function exposedServices(?array $system): array
    {
        $exposed = [];

        foreach ($system['listening'] ?? [] as $address) {
            $at = strrpos($address, ':');

            if ($at === false) {
                continue;
            }

            $port = (int) substr($address, $at + 1);
            $host = preg_replace('/%.*$/', '', trim(substr($address, 0, $at), '[]'));

            if (! isset(self::PRIVATE_SERVICES[$port])) {
                continue;
            }

            $everywhere = in_array($host, ['0.0.0.0', '::', '*'], true);
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

            if (($everywhere || $public) && ! isset($exposed[$port])) {
                $exposed[$port] = ['service' => self::PRIVATE_SERVICES[$port], 'port' => $port, 'address' => $address];
            }
        }

        return array_values($exposed);
    }

    /**
     * sshd's settings as it applies them: its defaults where the files say
     * nothing (when every file could be read).
     *
     * @param  ?SystemState  $system
     * @return ?array{permit_root_login: ?string, password_authentication: ?string}
     */
    public static function sshSettings(?array $system): ?array
    {
        $ssh = $system['ssh'] ?? null;

        if ($ssh === null) {
            return null;
        }

        return [
            'permit_root_login' => $ssh['permit_root_login'] ?? ($ssh['partial'] ? null : 'prohibit-password'),
            'password_authentication' => $ssh['password_authentication'] ?? ($ssh['partial'] ? null : 'yes'),
        ];
    }

    /**
     * What is wrong with what runs on the server: a system or a branch no
     * longer supported, flaws an update fixes, updates and a reboot waiting,
     * failed services, private services open to all, SSH open to passwords,
     * PHP showing its errors.
     *
     * @param  ?Software  $software
     * @return list<array{tone: string, label: string, detail: string}>
     */
    public static function alerts(?array $software): array
    {
        if ($software === null) {
            return [];
        }

        return [...self::softwareAlerts($software), ...self::systemAlerts($software['system'] ?? null)];
    }

    /**
     * @param  Software  $software
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function softwareAlerts(array $software): array
    {
        $alerts = [];
        $system = $software['os_status'] ?? null;

        if (($system['status'] ?? null) === 'eol') {
            $alerts[] = SiteAlerts::alert('bad', __(':system :version end of life', ['system' => self::SYSTEMS[$software['os']], 'version' => $software['os_version']]), __('The system no longer gets security fixes:eol, its packages included. Move to a supported release: :newest.', ['eol' => $system['eol'] ? ' '.__('since :date', ['date' => $system['eol']]) : '', 'newest' => $system['newest'] ?? '—']));
        }

        foreach ($software['programs'] as $program) {
            if (($program['status'] ?? null) === 'eol') {
                $alerts[] = SiteAlerts::alert('bad', __(':program end of life', ['program' => self::label($program)]), __('Its branch :cycle no longer gets fixes, security fixes included. :newest is the newest release.', ['cycle' => $program['cycle'], 'newest' => $program['newest'] ?? '—']));
            }

            if ($program['product'] === 'php' && self::isOn($program['settings']['display_errors'] ?? null)) {
                $alerts[] = SiteAlerts::alert('warn', __(':program shows its errors', ['program' => self::label($program)]), __('display_errors is on for PHP-FPM: an error shows visitors paths, queries, sometimes secrets. Turn it off in php.ini and log the errors instead.'));
            }

            $serious = array_filter(self::fixable($program), self::isSerious(...));

            if ($serious === []) {
                continue;
            }

            $worst = collect($serious)->contains(fn (array $flaw) => in_array($flaw['severity'], ['high', 'critical'], true));
            $alerts[] = SiteAlerts::alert(
                $worst ? 'bad' : 'warn',
                trans_choice(':program: :count flaw|:program: :count flaws', count($serious), ['program' => self::label($program)]),
                ($program['source'] ?? null) === 'distro'
                    ? __('Known flaws (CVE) fixed in a newer build of :package: update its packages (apt upgrade), then restart the service.', ['package' => $program['package']])
                    : __('Known flaws (CVE) of :version, fixed in newer releases:latest.', ['version' => self::upstreamVersion($program['version']), 'latest' => ($program['latest'] ?? null) ? ' '.__('(:latest is out)', ['latest' => $program['latest']]) : '']),
            );
        }

        return $alerts;
    }

    /**
     * @param  ?SystemState  $system
     * @return list<array{tone: string, label: string, detail: string}>
     */
    private static function systemAlerts(?array $system): array
    {
        if ($system === null) {
            return [];
        }

        $alerts = [];

        if ($system['failed_units'] !== null && $system['failed_units'] !== []) {
            $alerts[] = SiteAlerts::alert('bad', trans_choice(':count failed service|:count failed services', count($system['failed_units'])), __('systemd says these failed: :units. See systemctl status <name>.', ['units' => implode(', ', $system['failed_units'])]));
        }

        if (($system['security_updates'] ?? 0) > 0) {
            $alerts[] = SiteAlerts::alert('warn', trans_choice(':count security update|:count security updates', $system['security_updates']), $system['unattended_upgrades'] === false
                ? __('Waiting to be installed, and security updates are not installed by themselves: run apt upgrade, or turn on unattended-upgrades.')
                : __('Waiting to be installed: run apt upgrade.'));
        }

        if ($system['reboot_required'] === true) {
            $alerts[] = SiteAlerts::alert('warn', __('Reboot needed'), $system['reboot_packages'] === []
                ? __('Updates installed since the last start wait for a reboot to take effect.')
                : __('Updates installed since the last start wait for a reboot to take effect: :packages.', ['packages' => implode(', ', $system['reboot_packages'])]));
        }

        foreach (self::exposedServices($system) as $exposed) {
            $alerts[] = SiteAlerts::alert('warn', __(':service open to all', ['service' => $exposed['service']]), __('It listens on :address: anyone on the internet reaches it unless a firewall closes port :port. Bind it to 127.0.0.1 or a private address, or check the firewall (ufw status).', ['address' => $exposed['address'], 'port' => $exposed['port']]));
        }

        $ssh = self::sshSettings($system);

        if ($ssh !== null && ($ssh['permit_root_login'] ?? null) === 'yes' && ($ssh['password_authentication'] ?? null) === 'yes') {
            $alerts[] = SiteAlerts::alert('bad', __('SSH: root with a password'), __('sshd lets root log in with a password (PermitRootLogin yes, PasswordAuthentication yes): bots try it all day. Use keys, then set PasswordAuthentication no.'));
        } elseif ($ssh !== null && ($ssh['password_authentication'] ?? null) === 'yes') {
            $alerts[] = SiteAlerts::alert('warn', __('SSH takes passwords'), __('sshd accepts passwords (PasswordAuthentication yes): bots try them all day. Use keys, then set PasswordAuthentication no.'));
        }

        return $alerts;
    }

    /**
     * What tells a build apart: product, version, package and its version.
     *
     * @param  Program  $program
     * @return list<?string>
     */
    private static function identity(array $program): array
    {
        return [$program['product'], $program['version'], $program['package'] ?? null, $program['package_version'] ?? null];
    }

    /**
     * A system's branch only ends: a newer point release of it is no update to make.
     *
     * @param  array{status: string, cycle: ?string, latest: ?string, eol: ?string, newest: ?string}  $judged
     * @return array{status: string, cycle: ?string, latest: ?string, eol: ?string, newest: ?string}
     */
    private static function systemStatus(array $judged): array
    {
        return [...$judged, 'status' => $judged['status'] === 'update' ? 'current' : $judged['status']];
    }

    /**
     * A short text, or null when empty or not a text.
     */
    private static function text(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /**
     * The PHP settings read, kept to those Edgelog knows.
     *
     * @return ?array<string, string>
     */
    private static function phpSettings(mixed $settings): ?array
    {
        if (! is_array($settings)) {
            return null;
        }

        $kept = [];

        foreach (self::PHP_SETTINGS as $name) {
            if (($value = self::text($settings[$name] ?? null, 32)) !== null) {
                $kept[$name] = $value;
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * The machine's state, each value of the type it should be: null where it
     * could not be read.
     *
     * @param  array<string, mixed>  $sent
     * @return SystemState
     */
    private static function systemState(array $sent): array
    {
        $texts = fn (mixed $list, int $max) => is_array($list)
            ? array_values(array_slice(array_filter(array_map(fn (mixed $item) => self::text($item), $list)), 0, $max))
            : null;
        $count = fn (mixed $value) => is_int($value) && $value >= 0 ? $value : null;
        $ssh = is_array($sent['ssh'] ?? null) ? $sent['ssh'] : null;

        return [
            'reboot_required' => is_bool($sent['reboot_required'] ?? null) ? $sent['reboot_required'] : null,
            'reboot_packages' => $texts($sent['reboot_packages'] ?? [], 20) ?? [],
            'updates' => $count($sent['updates'] ?? null),
            'security_updates' => $count($sent['security_updates'] ?? null),
            'unattended_upgrades' => is_bool($sent['unattended_upgrades'] ?? null) ? $sent['unattended_upgrades'] : null,
            'listening' => $texts($sent['listening'] ?? null, 64),
            'ssh' => $ssh === null ? null : [
                'permit_root_login' => self::text($ssh['permit_root_login'] ?? null, 32) === null ? null : strtolower(self::text($ssh['permit_root_login'], 32)),
                'password_authentication' => self::text($ssh['password_authentication'] ?? null, 32) === null ? null : strtolower(self::text($ssh['password_authentication'], 32)),
                'partial' => in_array($ssh['partial'] ?? null, ['1', 1, true], true),
            ],
            'failed_units' => $texts($sent['failed_units'] ?? null, 20),
        ];
    }
}
