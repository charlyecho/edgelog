<?php

namespace App\Analytics;

use App\Enums\SourceType;

/**
 * Where a reader came from: the source type, the referrer, the campaign,
 * and the assistant whose citation it followed.
 */
final readonly class Sources
{
    public const string NO_REFERRER = '(none)';

    public const string THIS_SITE = '(this site)';

    public const string NO_CAMPAIGN = '(no campaign tag)';

    /**
     * @param  list<string>  $ownHosts  the site's own host names, lower case
     */
    /**
     * Social networks by their hosts and their apps' package names.
     *
     * @var array<string, string>
     */
    private const array NETWORKS = [
        '~(?:^|\.)(?:facebook\.com|fb\.com)$|^com\.facebook~' => 'Facebook',
        '~(?:^|\.)instagram\.com$|^com\.instagram~' => 'Instagram',
        '~(?:^|\.)(?:linkedin\.com|lnkd\.in)$|^com\.linkedin~' => 'LinkedIn',
        '~(?:^|\.)(?:t\.co|twitter\.com|x\.com)$|^com\.twitter~' => 'X',
        '~(?:^|\.)reddit\.com$|^com\.reddit~' => 'Reddit',
        '~(?:^|\.)(?:youtube\.com|youtu\.be)$|^com\.google\.android\.youtube~' => 'YouTube',
        '~(?:^|\.)pinterest\.[a-z.]+$~' => 'Pinterest',
        '~(?:^|\.)tiktok\.com$~' => 'TikTok',
        '~(?:^|\.)threads\.net$~' => 'Threads',
        '~(?:^|\.)ycombinator\.com$~' => 'Hacker News',
        '~(?:^|\.)quora\.com$~' => 'Quora',
        '~(?:^|\.)t\.me$|^org\.telegram~' => 'Telegram',
        '~(?:^|\.)whatsapp\.com$|^com\.whatsapp~' => 'WhatsApp',
        '~(?:^|\.)slack\.com$|^com\.slack~' => 'Slack',
        '~(?:^|\.)discord\.com$~' => 'Discord',
        '~(?:^|\.)bsky\.app$~' => 'Bluesky',
        '~(?:^|\.)mastodon\.social$~' => 'Mastodon',
    ];

    public function __construct(private array $ownHosts) {}

    /**
     * The assistant whose citation the referrer or the link's tag is, or ''.
     */
    public static function citedBy(string $referrer, string $url): string
    {
        if ($referrer !== '') {
            $parts = parse_url(str_contains($referrer, '//') ? $referrer : "//{$referrer}");
            $host = rtrim(strtolower($parts['host'] ?? ''), '.');

            foreach (Patterns::AI_REFERRER_HOSTS as $domain) {
                if ($host !== '' && ($host === $domain || str_ends_with($host, ".{$domain}"))) {
                    return self::assistantName($domain);
                }
            }

            foreach (Patterns::AI_REFERRER_PATHS as $domain => $prefix) {
                if (($host === $domain || str_ends_with($host, ".{$domain}")) && str_starts_with($parts['path'] ?? '', $prefix)) {
                    return self::assistantName($domain.$prefix);
                }
            }
        }

        if (preg_match(Patterns::AI_UTM, $url, $tag) === 1) {
            return self::assistantName($tag[1]);
        }

        return '';
    }

    /**
     * The canonical name of an assistant, from any name it was seen by.
     */
    public static function assistantName(string $name): string
    {
        $name = strtolower(trim($name));

        return Patterns::ASSISTANT_ALIASES[$name] ?? $name;
    }

    /**
     * The referrer's host, without "www."; an app's referrer gives its package id.
     */
    public static function referrerHost(string $referrer): string
    {
        if ($referrer === '') {
            return '';
        }

        $host = rtrim(strtolower(parse_url(str_contains($referrer, '//') ? $referrer : "//{$referrer}", PHP_URL_HOST) ?: ''), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The utm_ tags and click ids of a URL's query string.
     *
     * @return array<string, string>
     */
    public static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_change_key_case(array_filter($query, 'is_string'), CASE_LOWER);
    }

    /**
     * Where a page view came from.
     */
    public function sourceType(string $referrer, string $url, bool $cited): SourceType
    {
        if ($cited) {
            return SourceType::AiAssistant;
        }

        $host = self::referrerHost($referrer);

        if ($host !== '') {
            return match (true) {
                $this->isOwn($host) => SourceType::ThisSite,
                preg_match(Patterns::EMAIL_HOST, $host) === 1 => SourceType::Email,
                preg_match(Patterns::SEARCH_HOST, $host) === 1 => SourceType::SearchEngine,
                preg_match(Patterns::SOCIAL_HOST, $host) === 1 => SourceType::SocialMedia,
                default => SourceType::OtherWebsite,
            };
        }

        $tags = self::query($url);

        if (($tags['utm_source'] ?? '') !== '' || ($tags['utm_medium'] ?? '') !== '') {
            return self::utmKind($tags['utm_source'] ?? '') ?? self::utmKind($tags['utm_medium'] ?? '') ?? SourceType::TaggedLink;
        }

        foreach (Patterns::CLICK_IDS as $key => $kind) {
            if (($tags[$key] ?? '') !== '') {
                return SourceType::from($kind);
            }
        }

        return SourceType::Direct;
    }

    /**
     * The referrer as a reader's breakdown shows it.
     */
    public function referrerLabel(string $referrer): string
    {
        $host = self::referrerHost($referrer);

        return match (true) {
            $host === '' => self::NO_REFERRER,
            $this->isOwn($host) => self::THIS_SITE,
            default => substr($host, 0, 60),
        };
    }

    /**
     * The social network a referring host belongs to, by the name people
     * know it by, or the host itself when it is none of the known ones.
     */
    public static function network(string $host): string
    {
        foreach (self::NETWORKS as $pattern => $name) {
            if (preg_match($pattern, $host) === 1) {
                return $name;
            }
        }

        return $host;
    }

    /**
     * "source / medium / campaign" from the URL's utm_ tags.
     */
    public static function campaign(string $url): string
    {
        $tags = self::query($url);

        if (($tags['utm_source'] ?? '') === '' && ($tags['utm_medium'] ?? '') === '' && ($tags['utm_campaign'] ?? '') === '') {
            return self::NO_CAMPAIGN;
        }

        return substr(implode(' / ', [
            strtolower($tags['utm_source'] ?? '') ?: '-',
            strtolower($tags['utm_medium'] ?? '') ?: '-',
            strtolower($tags['utm_campaign'] ?? '') ?: '-',
        ]), 0, 120);
    }

    private function isOwn(string $host): bool
    {
        return in_array($host, $this->ownHosts, true) || in_array("www.{$host}", $this->ownHosts, true);
    }

    private static function utmKind(string $value): ?SourceType
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [];

        foreach (Patterns::UTM_WORDS as $kind => $keys) {
            if (array_intersect($words, $keys) !== []) {
                return SourceType::from($kind);
            }
        }

        return null;
    }
}
