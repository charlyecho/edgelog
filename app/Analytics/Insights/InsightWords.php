<?php

namespace App\Analytics\Insights;

use App\Analytics\Labels;
use App\Enums\SourceType;
use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * A finding in plain words: its sentence, its one number, and the tab that
 * shows it. Nothing is counted here.
 */
final class InsightWords
{
    /**
     * The kind of finding: [label, tone, icon].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function type(string $type): array
    {
        return match ($type) {
            'working' => [__('Working'), 'var(--good)', 'trend-up'],
            'dropped' => [__('Dropped'), 'var(--bad)', 'trend-down'],
            'new' => [__('New'), 'var(--group-people)', 'plus'],
            'gone' => [__('Gone'), 'var(--ink-3)', 'minus'],
            'anomaly' => [__('Unusual'), 'var(--warn)', 'pulse'],
            default => [__('Worth fixing'), 'var(--series-revenue)', 'sparkle'],
        };
    }

    public static function metric(string $metric): string
    {
        return match ($metric) {
            'visitors' => __('visitors'),
            'ai_fetches' => __('AI fetches'),
            'bots' => __('bot requests'),
            'search' => __('search crawls'),
            'server_errors' => __('server errors'),
            'not_found' => __('404s'),
            default => $metric,
        };
    }

    /**
     * The subject as a person reads it: a country by its flag and code, a source by its name.
     */
    public static function subject(string $kind, string $name): string
    {
        return match ($kind) {
            'country' => trim(Labels::flag($name).' '.$name),
            'source' => SourceType::tryFrom($name)?->label() ?? $name,
            default => $name,
        };
    }

    /**
     * The finding in one sentence.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function sentence(array $finding): string
    {
        $subject = self::subject($finding['kind'], $finding['name']);
        $metric = self::metric($finding['metric']);
        $span = $finding['window'] === '7' ? __('week on week') : __(':days days on the :days before', ['days' => $finding['window']]);

        return match ($finding['type']) {
            'working' => __(':subject: :metric up :change% :span', ['subject' => $subject, 'metric' => $metric, 'change' => round($finding['change']), 'span' => $span]),
            'dropped' => __(':subject: :metric down :change% :span', ['subject' => $subject, 'metric' => $metric, 'change' => round(-$finding['change']), 'span' => $span]),
            'gone' => __(':subject: no :metric in the last :days days', ['subject' => $subject, 'metric' => $metric, 'days' => $finding['window']]),
            'new' => __('New :kind: :subject', ['kind' => self::kind($finding['kind']), 'subject' => $subject]),
            'anomaly' => __(':metric: :move on :day', [
                'metric' => ucfirst($metric),
                'move' => $finding['after'] > $finding['before'] ? __('spike') : __('drop'),
                'day' => CarbonImmutable::parse($finding['to'])->translatedFormat('D j M'),
            ]),
            default => $finding['metric'] === 'ai_fetches'
                ? __(':subject: assistants fetched it :fetches times in 30 days, :people people read it', ['subject' => $subject, 'fetches' => number_format($finding['before']), 'people' => number_format($finding['after'])])
                : __(':subject: people reached this missing page from a link elsewhere: redirect it', ['subject' => $subject]),
        };
    }

    /**
     * The one number on the card, and the words beside it.
     *
     * @param  array<string, mixed>  $finding
     * @return array{0: int, 1: string}
     */
    public static function headline(array $finding): array
    {
        $metric = self::metric($finding['metric']);

        return match (true) {
            $finding['type'] === 'gone' => [$finding['before'], __(':metric before, none now', ['metric' => $metric])],
            $finding['type'] === 'anomaly' => [$finding['after'], __('against a usual :usual', ['usual' => number_format($finding['before'])])],
            $finding['type'] === 'opportunity' && $finding['metric'] === 'ai_fetches' => [$finding['before'], __('AI fetches in 30 days')],
            $finding['type'] === 'opportunity' => [$finding['after'], __('people from links elsewhere')],
            $finding['before'] === null => [$finding['after'], __(':metric, first time', ['metric' => $metric])],
            default => [$finding['after'], __(':metric, was :before', ['metric' => $metric, 'before' => number_format($finding['before'])])],
        };
    }

    /**
     * The tab that shows the finding: [route name, its label].
     *
     * @param  array<string, mixed>  $finding
     * @return array{0: string, 1: string}
     */
    public static function target(array $finding): array
    {
        $kind = $finding['kind'] === 'site' ? $finding['metric'] : $finding['kind'];

        return match ($kind) {
            'page' => ['teams.sites.traffic.pages', __('Pages')],
            'path', 'not_found' => ['teams.sites.site.not-found', __('Not found')],
            'source', 'referrer', 'campaign' => ['teams.sites.traffic.sources', __('Sources')],
            'country' => ['teams.sites.traffic.places', __('Places')],
            'assistant' => ['teams.sites.ai.assistants', __('Assistants')],
            'crawler', 'ai_fetches', 'search' => ['teams.sites.ai.crawlers', __('Crawlers')],
            'bots' => ['teams.sites.security.scanners', __('Security')],
            'server_errors' => ['teams.sites.site.health', __('Health')],
            default => ['teams.sites.show', __('Overview')],
        };
    }

    /**
     * The link to the tab that shows the finding, over its window.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function href(array $finding, Site $site): string
    {
        return route(self::target($finding)[0], [$site->team, $site, 'days' => $finding['window']]);
    }

    private static function kind(string $kind): string
    {
        return match ($kind) {
            'page' => __('page'),
            'source' => __('source'),
            'country' => __('country'),
            'referrer' => __('referring site'),
            'assistant' => __('assistant'),
            'campaign' => __('campaign'),
            'crawler' => __('crawler'),
            'path' => __('missing page'),
            default => $kind,
        };
    }
}
