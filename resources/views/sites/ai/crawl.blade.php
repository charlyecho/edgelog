@php
    use App\Analytics\Labels;

    $sitemapCount = count($health->sitemap);
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '—';
    $day = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->translatedFormat('j M');
    $nameCell = fn (string $label) => ['label' => $label, 'tile' => Labels::owner($label === 'Google' ? 'Googlebot' : ($label === 'Bing' ? 'bingbot' : $label)), 'note' => ($health->crawlers[$label]['user_fetcher'] ?? false) ? __('user fetcher') : null];
    $byWho = fn (array $by) => collect($by)->sortDesc()->map(fn ($count, $label) => "{$label} {$count}")->take(3)->implode(', ').(count($by) > 3 ? ' '.__('+:count more', ['count' => count($by) - 3]) : '');
    $headline = array_slice($health->crawlers, 0, 6, true);
    $notInSitemap = $health->notInSitemap();
    usort($notInSitemap, fn ($a, $b) => $health->pages[$b]['requests'] <=> $health->pages[$a]['requests']);
    $issueTitles = [
        'gone' => [__('Not found (404 and 410)'), __('Pages crawlers keep asking for that are gone. Redirect the ones with a new home; remove links and sitemap entries to the rest.')],
        'error' => [__('Server errors (5xx)'), __('The server failed a crawler. Several in a row make crawlers slow down or drop the page.')],
        'redirect' => [__('Redirects (3xx)'), __('Crawlers still asking for an old address. Point links and the sitemap at the final address.')],
        'slow' => [__('Slow for crawlers'), __('Pages the server took a second or more to answer a crawler. Only seen when the log records the time taken.')],
        'large' => [__('Large answers'), __('Bodies of 1 MB or more sent to crawlers.')],
    ];
@endphp

<x-section-frame section="ai" :team="$team" :site="$site" current="crawl" :title="__('Crawl health')"
    :lede="__('Which of the site\'s pages each crawler fetched, which it never did, which pages it fetched that the sitemap does not list, and the pages that answer crawlers with an error, a redirect, slowly or with a large body. Google and Bing, and every AI crawler and AI user fetcher by name.')">

    <section class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-rule bg-surface p-4 shadow-card text-[13px]">
        <p class="text-ink-2">
            @if ($sitemap === null)
                {{ __('The sitemap has not been read yet: it is read once a day, after robots.txt.') }}
            @elseif ($sitemapCount === 0)
                {{ __('No page listed in :sources (:error), :when: coverage cannot be told.', ['sources' => implode(', ', $sitemap->sources), 'error' => $sitemap->error, 'when' => $sitemap->fetched_at->diffForHumans()]) }}
            @else
                {{ trans_choice(':count page in the sitemap (:sources), read :when.|:count pages in the sitemap (:sources), read :when.', $sitemapCount, ['count' => number_format($sitemapCount), 'sources' => implode(', ', array_map(fn ($url) => parse_url($url, PHP_URL_PATH), $sitemap->sources)), 'when' => $sitemap->fetched_at->diffForHumans()]) }}
                @if ($sitemap->error)
                    <span class="text-warn">{{ $sitemap->error }}</span>
                @endif
            @endif
        </p>
        <div class="flex items-center gap-2">
            <x-segmented name="window" :current="$health->window" default="30" :options="[7 => __('Last 7 days'), 30 => __('Last 30 days')]" />
            @can('update', $site)
                <form method="POST" action="{{ route('teams.sites.ai.sitemap.refresh', [$team, $site]) }}">
                    @csrf
                    <x-button variant="secondary" size="sm">{{ __('Read it again') }}</x-button>
                </form>
            @endcan
        </div>
    </section>

    <p class="-mt-2 text-xs text-ink-3">{{ __(':verified of :days whole days counted, :from to :to. Days counted before paths were kept by crawler count as not fetched.', ['verified' => $health->period->verified()->count(), 'days' => $health->window, 'from' => $health->period->first->translatedFormat('j M'), 'to' => $health->period->last->translatedFormat('j M')]) }}</p>

    @if ($headline !== [] && $sitemapCount > 0)
        <section class="grid gap-3">
            <header>
                <h3 class="text-[15px] font-bold">{{ __('Coverage by crawler') }}</h3>
                <p class="text-[13px] text-ink-2">{{ __('The share of the sitemap\'s pages each crawler fetched at least once in the window; the line under it, how many it fetched each day.') }}</p>
            </header>
            <div class="overflow-hidden rounded-[10px] border border-rule bg-rule shadow-card">
                <div class="grid grid-cols-2 gap-px lg:grid-cols-3 xl:grid-cols-6">
                    @foreach ($headline as $label => $figures)
                        @php($covered = count($health->covered($label)))
                        <a href="{{ request()->fullUrlWithQuery(['crawler' => $label]) }}" @class(['bg-surface p-4 no-underline hover:bg-sunk', 'ring-2 ring-inset ring-ink' => $label === $crawler]) >
                            <p class="truncate text-[13px] font-semibold text-ink-2">{{ $label }}</p>
                            <p class="text-2xl font-bold tabular-nums text-ink">{{ $percent($covered, $sitemapCount) }}</p>
                            <p class="text-xs text-ink-3">{{ __(':covered of :count pages, :never never fetched', ['covered' => number_format($covered), 'count' => number_format($sitemapCount), 'never' => number_format($sitemapCount - $covered)]) }}</p>
                            <x-sparkline :values="$figures['fetched_daily']" color="var(--group-answer_engines)" :width="140" />
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <x-rows-table :title="__('Each crawler')" :unit="__('Select a crawler for the sitemap pages it never fetched.')"
        :columns="[[__('Crawler'), 'left'], [__('Requests'), 'right'], [__('Coverage'), 'right'], [__('Never fetched'), 'right'], [__('Not in sitemap'), 'right'], [__('404 / 410'), 'right'], [__('5xx'), 'right'], [__('Redirects'), 'right']]"
        :rows="collect($health->crawlers)->map(fn ($row, $label) => [
            $nameCell((string) $label),
            number_format($row['requests']),
            $sitemapCount > 0 ? $percent(count($health->covered((string) $label)), $sitemapCount) : '—',
            $sitemapCount > 0 ? number_format(count($health->neverFetched((string) $label))) : '—',
            number_format(count($health->notInSitemap((string) $label))),
            number_format($row['issues']['gone'] ?? 0),
            number_format($row['issues']['error'] ?? 0),
            number_format($row['issues']['redirect'] ?? 0),
        ])->values()->all()" :shown="15" :empty="__('No search or AI crawler in this window, or its days were counted before paths were kept by crawler.')" />

    @if ($crawler !== null && $sitemapCount > 0)
        @php($never = $health->neverFetched($crawler))
        <x-rows-table :title="__('Sitemap pages :crawler never fetched', ['crawler' => $crawler])" :unit="__('Orphans to it in the window. Link to them from pages it does read, or check they are not blocked.')"
            :columns="[[__('Page'), 'left'], [__('Other crawlers that fetched it'), 'right']]"
            :rows="array_map(fn ($path) => [['label' => $path, 'mono' => true], number_format(count($health->pages[$path]['by'] ?? $health->pages[rtrim($path, '/')]['by'] ?? []))], array_slice($never, 0, 500))"
            :shown="15" :empty="__(':crawler fetched every page of the sitemap.', ['crawler' => $crawler])" />
    @endif

    <x-rows-table :title="__('Fetched but not in the sitemap')" :unit="__('Pages crawlers fetched (and the site served) that the sitemap does not list. Add the ones people should find; redirect, remove or keep out the rest.')"
        :columns="[[__('Page'), 'left'], [__('Fetched'), 'right'], [__('By'), 'left'], [__('Days'), 'right'], [__('Last fetched'), 'right']]"
        :rows="array_map(fn ($path) => [
            ['label' => $path, 'mono' => true],
            number_format($health->pages[$path]['requests']),
            $byWho($health->pages[$path]['by']),
            number_format(count($health->pages[$path]['days'])),
            $day($health->pages[$path]['last']),
        ], array_slice($notInSitemap, 0, 500))" :shown="10"
        :empty="$sitemapCount > 0 ? __('Every page crawlers fetched is in the sitemap.') : __('Not told without a sitemap.')" />

    <section class="grid gap-4">
        <h3 class="text-[15px] font-bold">{{ __('Problems crawlers hit') }}</h3>
        @foreach ($issueTitles as $issue => [$title, $unit])
            @php($pages = $health->withIssue($issue))
            <x-rows-table :title="$title.' · '.trans_choice(':count page|:count pages', count($pages))" :unit="$unit"
                :columns="[[__('Page'), 'left'], [__('Answered so'), 'right'], [__('Crawler requests'), 'right'], [__('Crawlers'), 'left'], [__('In sitemap'), 'left'], [__('Last crawl'), 'right']]"
                :rows="collect($pages)->take(200)->map(fn ($count, $path) => [
                    ['label' => (string) $path, 'mono' => true],
                    number_format($count),
                    number_format($health->pages[$path]['requests']),
                    $byWho($health->pages[$path]['by']),
                    $health->inSitemap((string) $path) ? __('Listed') : '—',
                    $day($health->pages[$path]['last']),
                ])->values()->all()" :shown="10" :empty="__('None in this window.')" />
        @endforeach
        <p class="text-xs text-ink-3">{{ __('Slow: the server took 1,000 ms or more. Large: a body of 1 MB or more. Redirect chains cannot be told from a log (it carries no Location), so each redirect is counted on its own.') }}</p>
    </section>
</x-section-frame>
