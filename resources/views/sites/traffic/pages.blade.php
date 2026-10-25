@php
    use App\Analytics\Labels;
    use App\Enums\CountKind;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $others = CountKind::OTHERS;
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';
    $page = fn (string $path) => $path === $others ? ['label' => __('(all others)')] : ['label' => $path, 'icon' => Labels::pageIcon($path), 'mono' => true];

    // How long after it was published a page was first read, or fetched by Google.
    $after = function (?\Carbon\CarbonImmutable $published, ?\Carbon\CarbonImmutable $when) {
        if ($when === null) {
            return __('not yet');
        }

        $days = (int) $published->diffInDays($when);

        return match (true) {
            $days === 0 => __('the same day'),
            $days === 1 => __('the next day'),
            default => __(':count days after', ['count' => $days]),
        };
    };

    $week = $lastWeek->counts(CountKind::PeoplePage);
    $month = $lastMonth->counts(CountKind::PeoplePage);
    $monthVisitors = $lastMonth->counts(CountKind::PeoplePageVisitors);
    $publishedRows = $published->take(500)->map(fn ($sitePage) => [
        $page($sitePage->path),
        $sitePage->publishedOn()->translatedFormat('j M'),
        number_format((int) $sitePage->publishedOn()->diffInDays(now($site->timezone)->startOfDay())),
        number_format($week[$sitePage->path] ?? 0),
        number_format($month[$sitePage->path] ?? 0),
        number_format($monthVisitors[$sitePage->path] ?? 0),
        $after($sitePage->publishedOn(), $sitePage->first_google_on),
        $after($sitePage->publishedOn(), $sitePage->first_read_on),
    ])->all();

    $views = $period->counts(CountKind::PeoplePage);
    $visitors = $period->counts(CountKind::PeoplePageVisitors);
    $entries = $period->counts(CountKind::PeopleLanding);
    $asked = $period->counts(CountKind::EngineAskedPage);
    $training = $period->counts(CountKind::EngineTrainingPage);
    $search = $period->counts(CountKind::SearchPage);
    unset($views[$others]);
    arsort($views);
    $leaderboard = collect(array_slice($views, 0, 500, true))->map(fn ($count, $path) => [
        $page((string) $path),
        number_format($count),
        number_format($visitors[$path] ?? 0),
        number_format($entries[$path] ?? 0),
        number_format($asked[$path] ?? 0),
        number_format($training[$path] ?? 0),
        number_format($search[$path] ?? 0),
    ])->values()->all();

    $landing = $entries;
    $tail = $landing[$others] ?? null;
    unset($landing[$others]);
    arsort($landing);
    $landingRows = collect($tail === null ? $landing : [...$landing, $others => $tail])
        ->map(fn ($count, $path) => [$page((string) $path), number_format($count), $percent($count, $people->readers)])->values()->all();
    $hasVisitors = $period->hasVisitFigures();
@endphp

<x-section-frame :team="$team" :site="$site" current="pages" :title="__('Pages')" :period="$period" :choice="$choice">
    <x-rows-table :title="__('Pages by publish date')" :unit="__('Pages first seen in this period. A publish date is the first day the page was read by a person or fetched by Google, from what the logs hold: the day the logs begin for every page already there.')"
        :columns="[[__('Page'), 'left'], [__('Published'), 'right'], [__('Days since'), 'right'], [__('Views, 7 days'), 'right'], [__('Views, 30 days'), 'right'], [__('Visitors, 30 days'), 'right'], [__('First Google crawl'), 'right'], [__('First visitor'), 'right']]"
        :rows="$publishedRows" :shown="15" :empty="__('No page was first seen in this period.')" />

    <x-rows-table :title="__('Page leaderboard')" :unit="__('Views and visitors are people\'s; entries, the visits that started on the page; AI asked and AI training, the requests of assistants answering someone and of crawlers collecting for training; search, the requests of search engines.')"
        :columns="[[__('Page'), 'left'], [__('Views'), 'right'], [__('Visitors'), 'right'], [__('Entries'), 'right'], [__('AI asked'), 'right'], [__('AI training'), 'right'], [__('Search'), 'right']]"
        :rows="$leaderboard" :shown="15">
        @unless ($hasVisitors)
            <p class="mt-2 text-xs text-ink-3">{{ __('Some days of this period were counted before visitors were kept by page: their Visitors column leaves them out.') }}</p>
        @endunless
        @if (count($views) > 500)
            <p class="mt-2 text-xs text-ink-3">{{ __('The 500 most read pages of :count.', ['count' => number_format(count($views))]) }}</p>
        @endif
    </x-rows-table>

    <x-rows-table :title="__('Landing pages')" :unit="__('The first page each visitor read.')"
        :columns="[[__('Page'), 'left'], [__('Visitors'), 'right'], [__('Share'), 'right']]" :rows="$landingRows" :shown="15" />
</x-section-frame>
