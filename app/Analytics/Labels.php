<?php

namespace App\Analytics;

use App\Enums\SourceType;

/**
 * How a breakdown's rows are shown: a source's icon, a page's icon, a
 * country's flag, an assistant's owner.
 */
final class Labels
{
    /**
     * The companies behind the assistants' crawlers and fetchers: [pattern, letter, colour].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array OWNERS = [
        ['~^(?:GPTBot|ChatGPT|OAI-)~i', 'O', '#10a37f'],
        ['~^(?:Claude|anthropic)~i', 'A', '#d97757'],
        ['~^Perplexity~i', 'P', '#20808d'],
        ['~^(?:Google|Gemini|Googlebot|AdsBot|APIs-Google)~i', 'G', '#4285f4'],
        ['~^(?:meta-|FacebookBot)~i', 'M', '#0866ff'],
        ['~^(?:Amazonbot|Amzn)~i', 'A', '#e88a00'],
        ['~^Applebot~i', 'A', '#555555'],
        ['~^(?:bingbot|BingPreview)~i', 'M', '#0078d4'],
        ['~^MistralAI~i', 'M', '#fa520f'],
        ['~^Bytespider~i', 'B', '#25292e'],
        ['~^DuckDuck~i', 'D', '#de5833'],
    ];

    public static function sourceIcon(string $type): string
    {
        return match (SourceType::tryFrom($type)) {
            SourceType::SearchEngine => 'search',
            SourceType::SocialMedia => 'users',
            SourceType::Email => 'mail',
            SourceType::OtherWebsite => 'link',
            SourceType::AiAssistant => 'sparkle',
            SourceType::TaggedLink => 'tag',
            SourceType::ThisSite => 'home',
            default => 'arrow-right',
        };
    }

    public static function pageIcon(string $path): string
    {
        return $path === '/' ? 'home' : 'file';
    }

    public static function deviceIcon(string $device): string
    {
        return match ($device) {
            'Mobile' => 'phone',
            'Tablet' => 'tablet',
            default => 'monitor',
        };
    }

    /**
     * The flag of a two-letter country code, or '' when there is none.
     */
    public static function flag(string $code): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $code) !== 1) {
            return '';
        }

        return implode('', array_map(fn (string $letter) => mb_chr(0x1F1E6 + ord($letter) - ord('A')), str_split(strtoupper($code))));
    }

    /**
     * The letter and colour of the company behind a crawler or fetcher.
     *
     * @return array{0: string, 1: string}
     */
    public static function owner(string $name): array
    {
        foreach (self::OWNERS as [$pattern, $letter, $colour]) {
            if (preg_match($pattern, $name) === 1) {
                return [$letter, $colour];
            }
        }

        return [strtoupper(substr($name, 0, 1)) ?: '?', '#5a6672'];
    }

    /**
     * Determine if the assistant's agent fetches for a live question.
     */
    /**
     * An assistant by the name people know it by, from the domain its visitors come from.
     */
    public static function assistant(string $domain): string
    {
        return match ($domain) {
            'chatgpt.com' => 'ChatGPT',
            'claude.ai' => 'Claude',
            'perplexity.ai' => 'Perplexity',
            'gemini.google.com' => 'Gemini',
            'copilot.microsoft.com' => 'Copilot',
            'mistral.ai' => 'Mistral',
            'meta.ai' => 'Meta AI',
            'you.com' => 'You.com',
            'phind.com' => 'Phind',
            'poe.com' => 'Poe',
            'amazon.com' => 'Alexa',
            default => $domain,
        };
    }

    public static function isUserFetcher(string $name): bool
    {
        return preg_match(Patterns::AI_ASKED, $name) === 1;
    }
}
