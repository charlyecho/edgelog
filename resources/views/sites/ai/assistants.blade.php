@php
    use App\Analytics\Labels;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;
    use Illuminate\Support\Number;

    $others = CountKind::OTHERS;
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';
    $sorted = function (array $counts) use ($others) {
        unset($counts[$others]);
        arsort($counts);

        return $counts;
    };

    $asked = $sorted(array_filter($period->counts(CountKind::EngineName), fn (string $name) => Labels::isUserFetcher($name), ARRAY_FILTER_USE_KEY));
    $askedTotal = $period->counts(CountKind::EngineTier)['asked'] ?? 0;
    $kinds = ['page' => __('Page'), 'llms' => __('llms.txt'), 'markdown' => __('Markdown'), 'robots' => __('robots.txt'), 'sitemap' => __('Sitemap'), 'other' => __('Other file')];
    $fetches = array_intersect_key($crawlers->split(CountKind::CrawlerFetch), $asked);
    $byKind = [];
    foreach ($fetches as $kindsOf) {
        foreach ($kindsOf as $kind => $count) {
            $byKind[$kind] = ($byKind[$kind] ?? 0) + $count;
        }
    }
    arsort($byKind);
    $usedKinds = array_intersect_key($kinds, $byKind);

    $nameCell = fn (string $name, bool $fetcher = false) => ['label' => $name, 'tile' => Labels::owner($name), 'note' => $fetcher ? __('user fetcher') : null];
    $robotsCell = function (string $name) use ($verdicts) {
        $verdict = $verdicts->for($name);

        return ['label' => $verdict['label'], 'tone' => $verdict['tone']];
    };
    $costRows = fn (array $costs) => array_map(fn (array $row) => [
        $nameCell($row['name'], Labels::isUserFetcher($row['name'])),
        number_format($row['requests']),
        Number::fileSize($row['bytes'], precision: 1),
        $row['mean_ms'] === null ? '—' : __(':ms ms', ['ms' => number_format($row['mean_ms'])]),
        $row['p90_ms'] === null ? '—' : __(':ms ms', ['ms' => number_format($row['p90_ms'])]),
        $percent($row['errors'], $row['requests']),
        number_format($row['paths']),
        $row['paths'] > 0 ? number_format($row['requests'] / $row['paths'], 1).'×' : '',
        $row['last_seen_at']->setTimezone($site->timezone)->translatedFormat('j M H:i'),
    ], $costs);
    $costColumns = [[__('Name'), 'left'], [__('Requests'), 'right'], [__('Bytes sent'), 'right'], [__('Mean time'), 'right'], [__('p90 time'), 'right'], [__('Errors'), 'right'], [__('Distinct paths'), 'right'], [__('Re-crawl'), 'right'], [__('Last seen'), 'right']];

    $cited = $sorted($period->counts(CountKind::CitedBy));
    $likely = $sorted($period->counts(CountKind::AiLikelyVisit));
    $direct = $period->counts(CountKind::PeopleSource)['Direct or unknown'] ?? 0;
    $assistantFetches = $sorted($period->counts(CountKind::AiAskedFetch));
    $followed = $period->counts(CountKind::AiFetchFollowed);
    $readFirst = $period->counts(CountKind::AiCitedReadFirst);
    $landedRead = $period->counts(CountKind::AiCitedLandedRead);
    $assistants = array_keys($cited + $likely);

    $askedPages = $period->counts(CountKind::EngineAskedPage);
    $trainingPages = $period->counts(CountKind::EngineTrainingPage);
    $pages = $sorted($period->counts(CountKind::EnginePage));
    $pagesTotal = array_sum($period->counts(CountKind::EnginePage));
    $hasNewFigures = array_sum($period->counts(CountKind::CrawlerFetch)) === $period->group(TrafficGroup::AnswerEngines)->requests;
@endphp

<x-section-frame section="ai" :team="$team" :site="$site" current="assistants" :title="__('AI assistants')" :period="$period" :choice="$choice"
    :lede="__('AI working for someone waiting on an answer, and the visitors AI sent.')">
    @unless ($hasNewFigures)
        <x-notice tone="warn">{{ __('Some days of this period were counted before these figures were kept: count them again to see them all. The tables below leave them out.') }}</x-notice>
    @endunless

    <x-stat-row :stats="[
        [__('AI answering a question'), $askedTotal, __('Pages an assistant fetched because a person asked it something, right then (ChatGPT-User, Claude-User, Perplexity-User...).')],
        [__('Visitors from AI'), $period->citedVisits(), __('Visitors who followed a link in an AI assistant\'s answer, by the referrer or the link\'s utm_source. A floor: referrers are often stripped.')],
        [__('Likely from AI (inferred)'), array_sum($likely), __('Direct visits that landed on a page an assistant fetched for someone up to 15 minutes before. Likely, never certain; never added to the visitors from AI.')],
    ]" />

    <x-rows-table :title="__('Assistants answering a question, by name')" :unit="__('Pages fetched for a person\'s question. robots.txt: what the site\'s rules say to it (Crawler rules).')"
        :columns="[[__('Assistant'), 'left'], [__('robots.txt'), 'left'], [__('Fetches'), 'right'], [__('Share'), 'right']]"
        :rows="collect($asked)->map(fn ($count, $name) => [$nameCell((string) $name, true), $robotsCell((string) $name), number_format($count), $percent($count, $askedTotal)])->values()->all()"
        :empty="__('No assistant fetched a page for someone in this period.')" />

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <x-top-list :title="__('What they fetch')" :unit="__('Each fetch by what its address names: a page, llms.txt, a Markdown or text file, robots.txt, a sitemap, or another file.')"
            :rows="collect($byKind)->map(fn ($count, $kind) => ['label' => $kinds[$kind] ?? $kind, 'count' => $count])->values()->all()" :total="array_sum($byKind)" tint="var(--group-answer_engines)" />
        <x-rows-table :title="__('By assistant')" :columns="[[__('Assistant'), 'left'], ...array_map(fn ($label) => [$label, 'right'], array_values($usedKinds)), [__('Fetches'), 'right']]"
            :rows="collect($fetches)->sortByDesc(fn ($kindsOf) => array_sum($kindsOf))->map(fn ($kindsOf, $name) => [
                $nameCell((string) $name),
                ...array_map(fn ($kind) => number_format($kindsOf[$kind] ?? 0), array_keys($usedKinds)),
                number_format(array_sum($kindsOf)),
            ])->values()->all()" />
    </div>

    <x-rows-table :title="__('What each assistant costs')" :unit="__('Per assistant answering a question: requests, bytes the server sent, its time per request (when the log records it), failed requests (4xx or 5xx), distinct paths and how often it fetched them again. Distinct paths are summed day by day.')"
        :columns="$costColumns" :rows="$costRows($crawlers->costs(TrafficGroup::AnswerEngines, fn ($name) => Labels::isUserFetcher($name)))" />

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <x-top-list :title="__('AI assistants that sent visitors')" :unit="__('Visitors who followed a citation, by assistant. Counted once per visitor; undercounts.')"
            :rows="collect($cited)->map(fn ($count, $domain) => ['label' => Labels::assistant((string) $domain), 'count' => $count])->values()->all()" :total="$period->citedVisits()" tint="var(--group-answer_engines)">
            <p class="mt-2 text-xs text-ink-3">{{ __('Google AI Overview clicks look exactly like ordinary Google clicks and are counted as search.') }}</p>
        </x-top-list>
        <x-top-list :title="__('Likely from AI assistants (inferred)')" :unit="__('Direct visits that probably came from an AI answer. An estimate, kept apart from the counted AI source.')"
            :rows="collect($likely)->map(fn ($count, $domain) => ['label' => Labels::assistant((string) $domain), 'count' => $count])->values()->all()" :total="max(1, array_sum($likely))" tint="var(--gap)">
            <p class="mt-2 text-xs text-ink-3">{{ __(':likely likely of :direct direct, untagged visits (:share of them). Inferred, not counted: a visit with no referrer and no campaign tag that landed on a page an assistant\'s user fetcher had fetched up to 15 minutes before. Likely, never certain.', ['likely' => number_format(array_sum($likely)), 'direct' => number_format($direct), 'share' => $percent(array_sum($likely), $direct)]) }}</p>
        </x-top-list>
    </div>

    <x-rows-table :title="__('Fetches followed by a visit (likely)')" :unit="__('Of each assistant\'s fetches for someone\'s question, how many were likely followed by that person\'s click. Inferred, never certain.')"
        :columns="[[__('Assistant'), 'left'], [__('Fetches for a question'), 'right'], [__('Likely followed by a visit'), 'right'], [__('Share (likely)'), 'right']]"
        :rows="collect($assistantFetches)->map(fn ($count, $domain) => [
            Labels::assistant((string) $domain),
            number_format($count),
            number_format($followed[$domain] ?? 0),
            $count >= 10 ? $percent($followed[$domain] ?? 0, $count) : __('too few (:count)', ['count' => $count]),
        ])->values()->all()" :empty="__('No assistant fetched a page for someone in this period.')" />

    <x-rows-table :title="__('What the AI read before it sent a visitor')" :unit="__('Visits from each assistant\'s answers, how many came after its user fetcher read pages in the 15 minutes before, and how many landed on a page it read.')"
        :columns="[[__('Assistant'), 'left'], [__('Visits from its answers'), 'right'], [__('It fetched pages first'), 'right'], [__('Landed on a page it fetched'), 'right'], [__('Likely visits (inferred)'), 'right']]"
        :rows="array_map(fn ($domain) => [
            Labels::assistant((string) $domain),
            number_format($cited[$domain] ?? 0),
            number_format($readFirst[$domain] ?? 0),
            number_format($landedRead[$domain] ?? 0),
            number_format($likely[$domain] ?? 0),
        ], $assistants)" :empty="__('No visit from an AI answer in this period.')" />

    <x-rows-table :title="__('Pages AI assistants fetched')" :unit="__('Every AI request by path: for a question, and for training or AI search.')"
        :columns="[[__('Page'), 'left'], [__('For a question'), 'right'], [__('For training'), 'right'], [__('Requests'), 'right'], [__('Share'), 'right']]"
        :rows="collect($pages)->take(300)->map(fn ($count, $path) => [
            ['label' => (string) $path, 'mono' => true],
            number_format($askedPages[$path] ?? 0),
            number_format($trainingPages[$path] ?? 0),
            number_format($count),
            $percent($count, $pagesTotal),
        ])->values()->all()" :shown="15" />
</x-section-frame>
