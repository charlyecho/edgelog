@php
    use App\Analytics\Crawlers\CrawlerCollector;
    use App\Analytics\Labels;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;
    use Illuminate\Support\Number;

    $others = CountKind::OTHERS;
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';
    $sorted = function (array $counts) use ($others) {
        $tail = $counts[$others] ?? null;
        unset($counts[$others]);
        arsort($counts);

        return $tail === null ? $counts : [...$counts, $others => $tail];
    };

    $training = $sorted(array_filter($period->counts(CountKind::EngineName), fn (string $name) => ! Labels::isUserFetcher($name), ARRAY_FILTER_USE_KEY));
    $trainingTotal = $period->counts(CountKind::EngineTier)['training'] ?? 0;
    $kinds = ['page' => __('Page'), 'llms' => __('llms.txt'), 'markdown' => __('Markdown'), 'robots' => __('robots.txt'), 'sitemap' => __('Sitemap'), 'other' => __('Other file')];
    $fetches = array_intersect_key($crawlers->split(CountKind::CrawlerFetch), $training);
    $byKind = [];
    foreach ($fetches as $kindsOf) {
        foreach ($kindsOf as $kind => $count) {
            $byKind[$kind] = ($byKind[$kind] ?? 0) + $count;
        }
    }
    arsort($byKind);
    $usedKinds = array_intersect_key($kinds, $byKind);

    $search = $period->group(TrafficGroup::SearchEngines);
    $bots = $period->group(TrafficGroup::Bots);
    $probes = $bots->probes;
    $botNames = $sorted($period->counts(CountKind::BotAgent));
    $previews = array_filter($botNames, fn (string $name) => str_starts_with($name, 'link preview: '), ARRAY_FILTER_USE_KEY);
    // The two bots that asked for a path most, and how many others did.
    $botsCell = fn (array $bots) => collect($bots)->take(2)->map(fn ($count, $name) => $name.' '.number_format($count))->implode(', ')
        .(count($bots) > 2 ? ' +'.(count($bots) - 2) : '');

    $nameCell = fn (string $name, bool $fetcher = false) => $name === $others ? ['label' => __('(all others)')] : ['label' => $name, 'tile' => Labels::owner($name), 'note' => $fetcher ? __('user fetcher') : null];
    $robotsCell = function (string $name) use ($verdicts) {
        $verdict = $verdicts->for($name);

        return ['label' => $verdict['label'], 'tone' => $verdict['tone']];
    };
    $groupWords = [TrafficGroup::AnswerEngines->value => __('AI'), TrafficGroup::SearchEngines->value => __('Search'), TrafficGroup::Bots->value => __('Bot'), TrafficGroup::Ours->value => __('Ours')];
    $costs = array_map(fn (array $row) => [
        $nameCell($row['name'] === CrawlerCollector::PROBES ? __('(scanner probes)') : $row['name'], Labels::isUserFetcher($row['name'])),
        $groupWords[$row['group']->value] ?? $row['group']->value,
        number_format($row['requests']),
        Number::fileSize($row['bytes'], precision: 1),
        $row['mean_ms'] === null ? '—' : __(':ms ms', ['ms' => number_format($row['mean_ms'])]),
        $row['p90_ms'] === null ? '—' : __(':ms ms', ['ms' => number_format($row['p90_ms'])]),
        $percent($row['errors'], $row['requests']),
        number_format($row['paths']),
        $row['paths'] > 0 ? number_format($row['requests'] / $row['paths'], 1).'×' : '',
        $row['last_seen_at']->setTimezone($site->timezone)->translatedFormat('j M H:i'),
    ], $crawlers->costs());
@endphp

<x-section-frame section="ai" :team="$team" :site="$site" current="crawlers" :title="__('Crawlers')" :period="$period" :choice="$choice"
    :lede="__('Everything with nobody waiting on an answer: AI collecting for training, search engines, and bots.')">
    <x-stat-row :stats="[
        [__('AI collecting for training'), $trainingTotal, __('Requests to train a model or feed AI search: GPTBot, ClaudeBot, PerplexityBot, CCBot...')],
        [__('Search engines'), $search->requests, __('Requests from search crawlers: Googlebot, bingbot, Applebot...')],
        [__('Bots and scanners'), $bots->requests, __('Every other machine: scripts, libraries, link previews, scrapers in a browser\'s clothes, scanners.')],
        [__('of which scanner probes'), $probes, __('Requests for files and panels a site does not serve to readers. See Security.')],
    ]" />

    <x-rows-table :title="__('AI collecting for training, by name')" :unit="__('Requests to train a model or feed AI search. robots.txt: what the site\'s rules say to it (Crawler rules).')"
        :columns="[[__('Crawler'), 'left'], [__('robots.txt'), 'left'], [__('Fetches'), 'right'], [__('Share'), 'right']]"
        :rows="collect($training)->map(fn ($count, $name) => [$nameCell((string) $name), $name === $others ? '' : $robotsCell((string) $name), number_format($count), $percent($count, $trainingTotal)])->values()->all()"
        :empty="__('No AI crawler collected for training in this period.')" />

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <x-top-list :title="__('What they fetch')" :unit="__('Each fetch by what its address names.')"
            :rows="collect($byKind)->map(fn ($count, $kind) => ['label' => $kinds[$kind] ?? $kind, 'count' => $count])->values()->all()" :total="array_sum($byKind)" tint="var(--group-answer_engines)" />
        <x-rows-table :title="__('By crawler')" :columns="[[__('Crawler'), 'left'], ...array_map(fn ($label) => [$label, 'right'], array_values($usedKinds)), [__('Fetches'), 'right']]"
            :rows="collect($fetches)->sortByDesc(fn ($kindsOf) => array_sum($kindsOf))->map(fn ($kindsOf, $name) => [
                $nameCell((string) $name),
                ...array_map(fn ($kind) => number_format($kindsOf[$kind] ?? 0), array_keys($usedKinds)),
                number_format(array_sum($kindsOf)),
            ])->values()->all()" />
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <x-top-list :title="__('Search engines')" :unit="__('requests')" :rows="collect($sorted($period->counts(CountKind::SearchEngine)))->map(fn ($count, $name) => ['label' => $name === $others ? __('(all others)') : (string) $name, 'tile' => $name === $others ? null : Labels::owner((string) $name), 'count' => $count])->values()->all()"
            :total="$search->requests" tint="var(--group-search_engines)" />
        <x-top-list :title="__('Bots and scanners by name')" :unit="__('requests')" :rows="collect($botNames)->map(fn ($count, $name) => ['label' => $name === $others ? __('(all others)') : (string) $name, 'count' => $count])->values()->all()"
            :total="$bots->requests" tint="var(--group-bots)" :shown="10" />
    </div>

    {{-- What bots fetch: every path they asked for, or one bot's. --}}
    <div class="grid gap-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]">
            <span class="font-semibold text-ink-2">{{ __('Bot') }}</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach (['' => __('Every bot'), ...collect($botNames)->except($others)->keys()->take(12)->mapWithKeys(fn ($name) => [(string) $name => (string) $name])->all(), ...($bot !== null ? [$bot => $bot] : [])] as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery(['bot' => $key === '' ? null : $key]) }}" @class([
                        'inline-flex h-7 max-w-64 items-center truncate rounded-full border px-2.5 no-underline',
                        'border-ink bg-ink text-surface' => ($bot ?? '') === $key,
                        'border-rule bg-surface text-ink hover:border-ink-3' => ($bot ?? '') !== $key,
                    ])>{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <x-rows-table :title="$bot === null ? __('What bots fetch') : __('What :bot fetches', ['bot' => $bot])"
            :unit="__('The paths bots and scanners asked for most. Each day keeps its 1,000 busiest bot and path pairs; days counted before paths were kept by bot are left out.')"
            :columns="$bot === null ? [[__('Path'), 'left'], [__('Requests'), 'right'], [__('Bots'), 'left']] : [[__('Path'), 'left'], [__('Requests'), 'right']]"
            :rows="collect($botPaths)->map(fn ($row) => [
                ['label' => $row['path'], 'mono' => true],
                number_format($row['requests']),
                ...($bot === null ? [$botsCell($row['bots'])] : []),
            ])->all()" :shown="15" :empty="__('Not recorded for this period: count its days again.')" />
    </div>

    <x-rows-table :title="__('What each crawler costs')" :unit="__('Per crawler: requests, bytes the server sent, its time per request (when the log records it), failed requests (4xx or 5xx), distinct paths and how often it fetched them again. Each day keeps its 50 busiest names per group; the rest are one “(all others)” row.')"
        :columns="[[__('Name'), 'left'], [__('Group'), 'left'], [__('Requests'), 'right'], [__('Bytes sent'), 'right'], [__('Mean time'), 'right'], [__('p90 time'), 'right'], [__('Errors'), 'right'], [__('Distinct paths'), 'right'], [__('Re-crawl'), 'right'], [__('Last seen'), 'right']]"
        :rows="$costs" :shown="15" :empty="__('Not recorded for this period: count its days again.')" />

    <x-top-list :title="__('Link previews')" :unit="__('Someone pasted a link to the site in a chat; the app fetched it to draw a preview. Counted as bots.')"
        :rows="collect($previews)->map(fn ($count, $name) => ['label' => substr((string) $name, strlen('link preview: ')), 'count' => $count])->values()->all()" :total="max(1, array_sum($previews))" tint="var(--group-bots)" />
</x-section-frame>
