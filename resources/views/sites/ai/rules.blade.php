@php
    use App\Analytics\Labels;

    $requests = array_map('array_sum', $crawlers->split(\App\Enums\CountKind::CrawlerPath));
    unset($requests[\App\Enums\CountKind::OTHERS]);
    arsort($requests);
    $rows = collect($requests)->map(function ($count, $name) use ($verdicts) {
        $verdict = $verdicts->for((string) $name);

        return [
            ['label' => (string) $name, 'tile' => Labels::owner((string) $name), 'note' => Labels::isUserFetcher((string) $name) ? __('user fetcher') : null],
            $verdict['group'] === null ? ['label' => __('none'), 'tone' => 'muted'] : ['label' => $verdict['group'] === '*' ? __('* (everyone)') : $verdict['group'], 'mono' => true],
            ['label' => $verdict['label'], 'tone' => $verdict['tone']],
            number_format($count),
            $verdict['off_limits'] === 0 ? '0' : ['label' => number_format($verdict['off_limits']), 'tone' => 'bad', 'note' => collect($verdict['paths'])->map(fn ($fetches, $path) => "{$path} ({$fetches})")->implode(', ')],
        ];
    })->values()->all();
@endphp

<x-section-frame section="ai" :team="$team" :site="$site" current="rules" :title="__('Crawler rules')" :period="$period" :choice="$choice"
    :lede="__('What the site\'s robots.txt says to each crawler, and whether it kept to it. To let a crawler in or keep it out, edit robots.txt on your site: Edgelog never writes to it.')">
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-rule bg-surface p-4 shadow-card text-[13px]">
        <p class="text-ink-2">
            @if ($robots === null)
                {{ __('robots.txt has not been read yet: it is read once a day.') }}
            @elseif ($robots->error)
                {{ __('robots.txt could not be read at :url (:error), :when.', ['url' => $robots->url, 'error' => $robots->error, 'when' => $robots->fetched_at->diffForHumans()]) }}
            @elseif ($robots->body === null)
                {{ __(':url answered :status, :when: no rules, so every crawler is allowed.', ['url' => $robots->url, 'status' => $robots->status, 'when' => $robots->fetched_at->diffForHumans()]) }}
            @else
                {{ __('Read from :url :when. Each crawler is judged by its own token against these rules, past days included: a rule changed since is not taken into account.', ['url' => $robots->url, 'when' => $robots->fetched_at->diffForHumans()]) }}
            @endif
        </p>
        @can('update', $site)
            <form method="POST" action="{{ route('teams.sites.ai.robots.refresh', [$team, $site]) }}">
                @csrf
                <x-button variant="secondary" size="sm">{{ __('Read it again') }}</x-button>
            </form>
        @endcan
    </section>

    <x-rows-table :title="__('Crawlers and robots.txt')" :unit="__('AI assistants, AI crawlers and search engines of the period. Off-limits fetches: requests to a path the rules keep it out of.')"
        :columns="[[__('Crawler'), 'left'], [__('Rule that applies'), 'left'], [__('robots.txt'), 'left'], [__('Requests'), 'right'], [__('Off-limits fetches'), 'right']]"
        :rows="$rows" :shown="20" :empty="__('No AI or search crawler in this period, or its days were counted before paths were kept by crawler.')" />

    @if ($robots?->body)
        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <h3 class="mb-2 text-[14px] font-bold">{{ __('The rules as written') }}</h3>
            <pre class="max-h-96 overflow-auto rounded-lg bg-sunk p-3 font-mono text-xs text-ink">{{ $robots->body }}</pre>
        </section>
    @endif
</x-section-frame>
