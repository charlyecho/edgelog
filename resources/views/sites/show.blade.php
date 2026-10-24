@php
    use App\Analytics\Labels;
    use App\Analytics\SitePeriod;
    use App\Enums\CountKind;
    use App\Enums\DayStatus;
    use App\Enums\EngineTier;
    use App\Enums\SourceType;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $before = $previous->group(TrafficGroup::People);
    $engines = $period->group(TrafficGroup::AnswerEngines);
    $comparable = $period->comparableWith($previous);
    $verified = $period->verified()->count();
    $whole = $period->verified()->filter(fn ($day) => ! $day->isPartial())->count();
    $daily = fn (TrafficGroup $group, string $figure) => $period->daily(fn ($day) => SitePeriod::figureOf($day, $group, $figure));
    $perVisit = fn ($figures) => $figures->readers > 0 ? $figures->views / $figures->readers : 0;
    $noEarlier = __('no whole period before to compare');
    $revenue = $period->revenue();
    $currency = array_key_first($revenue);
    $showRevenue = $currency !== null || $site->integrations()->exists();
    $signedIn = $period->signedInUsers();
    $signedInBefore = $previous->signedInUsers();
    $showUsers = $signedIn > 0 || $signedInBefore > 0;
    $secondRow = 3 + ($showRevenue ? 1 : 0) + ($showUsers ? 1 : 0);
    $otherCurrencies = collect($revenue)->except($currency)->map(fn ($money, $code) => \App\Integrations\Money::format($money['net'], $code))->implode(' · ');
    $tiers = $period->top(CountKind::EngineTier);
    $others = CountKind::OTHERS;
    $label = fn (string $name, string $fallback) => $name === $others ? __('(all others)') : $fallback;

    $countries = collect($period->top(CountKind::PeopleCountry, 50))->map(fn ($count, $code) => [
        'label' => $code === 'Unknown' ? __('Not recorded') : $label($code, $code),
        'flag' => Labels::flag($code),
        'count' => $count,
    ])->values()->all();
    $pages = collect($period->top(CountKind::PeoplePage, 50))->map(fn ($count, $path) => [
        'label' => $label($path, $path),
        'icon' => Labels::pageIcon($path),
        'count' => $count,
    ])->values()->all();
    $sources = collect($period->top(CountKind::PeopleSource, 50))->map(fn ($count, $type) => [
        'label' => $label($type, SourceType::tryFrom($type)?->label() ?? $type),
        'icon' => Labels::sourceIcon($type),
        'count' => $count,
    ])->values()->all();
    $assistants = collect($period->top(CountKind::EngineName, 50))->map(fn ($count, $name) => [
        'label' => $label($name, $name),
        'tile' => Labels::owner($name),
        'note' => Labels::isUserFetcher($name) ? __('user fetcher') : null,
        'count' => $count,
    ])->values()->all();
@endphp

<x-layouts.app :title="__('Overview')">
    <x-slot:toolbar>
        <x-button variant="secondary" size="sm" :href="route('teams.sites.realtime', [$team, $site])">
            <span class="size-2 rounded-full border-2 border-good"></span> {{ __('Today') }} · {{ __('Live') }}
        </x-button>
        <x-period-switch :current="$choice" />
    </x-slot:toolbar>

    <x-site-tabs :team="$team" :site="$site" current="summary" />

    <p class="mb-3 text-[13px] text-ink-2">
        @if ($period->length() === 1)
            {{ __('Yesterday, :day', ['day' => $period->first->translatedFormat('l j M')]) }}
        @else
            {{ __('Last :count whole days, :from to :to', ['count' => $period->length(), 'from' => $period->first->translatedFormat('j M'), 'to' => $period->last->translatedFormat('j M')]) }}
        @endif
        ·
        @if ($whole === $period->length())
            {{ $period->length() === 1 ? __('the day is whole and verified') : __('every day whole and verified') }}
        @else
            {{ __(':verified of :days days verified', ['verified' => $verified, 'days' => $period->length()]) }}
            @if ($period->withheld() !== [])
                · <span class="text-bad">{{ trans_choice(':count withheld|:count withheld', count($period->withheld())) }}</span>
            @endif
            @if ($period->pending() !== [])
                · {{ trans_choice(':count being checked|:count being checked', count($period->pending())) }}
            @endif
            @if ($period->missing() !== [])
                · {{ trans_choice(':count not recorded|:count not recorded', count($period->missing())) }}
            @endif
        @endif
        · {{ __('All pages') }}
    </p>

    @if (! $period->hasFigures())
        <x-card :title="__('No figures yet')" :description="__('A day is counted once it is over where the site is, then counted a second time by the audit. Its figures show here only when both counts agree.')">
            <p class="text-ink-2">
                @can('update', $site)
                    {{ __('Check that the site sends its log in') }} <a href="{{ route('teams.sites.edit', [$team, $site]) }}" class="font-semibold text-ink underline">{{ __('Site settings') }}</a>.
                @else
                    {{ __('The first figures appear the day after the site sends its first log lines.') }}
                @endif
            </p>
        </x-card>
    @else
        <div class="grid gap-5">
            <div class="overflow-hidden rounded-[10px] border border-rule bg-rule shadow-card">
                <div class="grid grid-cols-2 gap-px lg:grid-cols-4">
                    <x-kpi :label="__('Visitors')" :info="__('People who read at least one page: a visit is one address and browser, with no gap over 30 minutes.')"
                        :value="$people->readers" :previous="$before->readers" :comparable="$comparable" :note="$noEarlier"
                        :series="$daily(TrafficGroup::People, 'readers')" color="var(--series-visitors)" />
                    <x-kpi :label="__('Pages read')" :info="__('Pages people read: a page answered 200 or 304, never a file, a redirect or a framework\'s data fetch.')"
                        :value="$people->views" :previous="$before->views" :comparable="$comparable" :note="$noEarlier"
                        :series="$daily(TrafficGroup::People, 'views')" color="var(--series-pages)" />
                    <x-kpi :label="__('Visitors from AI')" :info="__('Visitors who followed a link in an AI assistant\'s answer, by the referrer or the link\'s utm_source. A floor: referrers are often stripped.')"
                        :value="$period->citedVisits()" :previous="$previous->citedVisits()" :comparable="$comparable" :note="$noEarlier"
                        :series="$period->daily(fn ($day) => (int) $day->cited_visits)" color="var(--group-answer_engines)" />
                    <x-kpi :label="__('AI assistants')" :info="__('Requests from AI assistants\' crawlers and fetchers, to train a model or to answer someone\'s question.')"
                        :value="$engines->requests" :previous="$previous->group(TrafficGroup::AnswerEngines)->requests" :comparable="$comparable" :note="$noEarlier"
                        :series="$daily(TrafficGroup::AnswerEngines, 'requests')" color="var(--group-answer_engines)" />
                </div>
                <div @class(['mt-px grid grid-cols-2 gap-px', 'lg:grid-cols-3' => $secondRow === 3, 'lg:grid-cols-4' => $secondRow === 4, 'lg:grid-cols-5' => $secondRow === 5])>
                    @if ($showRevenue)
                        <x-kpi :label="__('Revenue')" :info="__('Live payments reported by Stripe, refunds taken off, in the currency most paid in. Never added across currencies.')"
                            :value="$currency ? $revenue[$currency]['net'] : 0" format="money" :currency="$currency ?? 'eur'"
                            :previous="$currency ? ($previous->revenue()[$currency]['net'] ?? 0) : null" :comparable="$comparable && $currency !== null" :note="$currency ? $noEarlier : __('no payment in this period')"
                            :extra="$currency ? trans_choice(':count payment|:count payments', $revenue[$currency]['payments']).($otherCurrencies !== '' ? ' · '.__('also :amounts', ['amounts' => $otherCurrencies]) : '') : null"
                            :series="$currency ? $period->dailyRevenue($currency) : []" color="var(--series-revenue)" />
                    @endif
                    @if ($showUsers)
                        <x-kpi :label="__('Signed-in users')" :info="__('Distinct users your app named in the log\'s uid= field: someone seen on several days counts once.')"
                            :value="$signedIn" :previous="$signedInBefore" :comparable="$comparable" :note="$noEarlier"
                            :extra="__(':count visitors were signed in', ['count' => number_format($period->verified()->sum('signed_in_readers'))])"
                            :series="$period->daily(fn ($day) => (int) $day->signed_in_users)" color="var(--series-visitors)" />
                    @endif
                    <x-kpi :label="__('Pages per visit')" :info="__('Pages read divided by visitors.')"
                        :value="$perVisit($people)" format="ratio" :previous="$perVisit($before)" :comparable="$comparable" :note="$noEarlier"
                        :series="$period->daily(fn ($day) => SitePeriod::figureOf($day, TrafficGroup::People, 'readers') > 0 ? (int) round(SitePeriod::figureOf($day, TrafficGroup::People, 'views') / SitePeriod::figureOf($day, TrafficGroup::People, 'readers') * 10) : 0)" color="var(--series-visitors)" />
                    <x-kpi :label="__('Search engines')" :info="__('Requests from search engines\' crawlers: Google, Bing and other classic indexing.')"
                        :value="$period->group(TrafficGroup::SearchEngines)->requests" :previous="$previous->group(TrafficGroup::SearchEngines)->requests" :comparable="$comparable" :note="$noEarlier"
                        :series="$daily(TrafficGroup::SearchEngines, 'requests')" color="var(--group-search_engines)" />
                    <x-kpi :label="__('Bots and scanners')" :info="__('Monitors, SEO tools, link previews, scripts, and probes hunting for secrets.')"
                        :value="$period->group(TrafficGroup::Bots)->requests" :previous="$previous->group(TrafficGroup::Bots)->requests" :comparable="$comparable" :note="$noEarlier"
                        :series="$daily(TrafficGroup::Bots, 'requests')" color="var(--group-bots)" />
                </div>
            </div>

            <p class="max-w-[110ch] text-ink-2">
                {{ __(':visitors visitors read :pages pages, which took :requests requests to the server (pages, images, scripts and styles).', [
                    'visitors' => number_format($people->readers),
                    'pages' => number_format($people->views),
                    'requests' => number_format($people->requests),
                ]) }}
                @if ($comparable)
                    {{ __('Changes are against :from to :to.', ['from' => $previous->first->translatedFormat('j M'), 'to' => $previous->last->translatedFormat('j M')]) }}
                @endif
            </p>

            @if ($period->length() > 1)
                <x-card :title="__('Visitors and pages read, by day')" :description="__('A line breaks at a day that is not whole: withheld because its two counts disagree, being checked, or not recorded. A missing day is never drawn as zero.')">
                    <x-charts.daily-chart :series="$period->series()" />
                </x-card>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                <x-top-list :title="__('Countries')" :unit="__('visitors')" :rows="$countries" :total="$people->readers" />
                <x-top-list :title="__('Top pages')" :unit="__('pages read')" :rows="$pages" :total="$people->views" />
                <x-top-list :title="__('Top sources')" :unit="__('visitors, by where they came from')" :rows="$sources" :total="$people->readers" />
                <x-top-list :title="__('AI assistants')" :unit="__('requests, answering a question or collecting for training')" :rows="$assistants" :total="$engines->requests" tint="var(--group-answer_engines)" />
            </div>

            <section class="grid gap-2">
                <h2 class="flex items-center gap-1.5 text-[15px] font-bold">
                    {{ __('Who else came') }}
                    <x-info :text="__('Every request that is not a person reading, by group. With the people, the groups add up to the log.')" />
                </h2>
                <div class="flex flex-wrap gap-x-6 gap-y-2 rounded-[10px] border border-rule bg-surface px-4 py-3 shadow-card">
                    @foreach ([
                        [__('AI answering a question now'), $tiers[EngineTier::Asked->value] ?? 0, 'var(--group-answer_engines)'],
                        [__('AI collecting for training'), $tiers[EngineTier::Training->value] ?? 0, 'var(--group-answer_engines)'],
                        [__('Search engines'), $period->group(TrafficGroup::SearchEngines)->requests, 'var(--group-search_engines)'],
                        [__('Bots and scanners'), $period->group(TrafficGroup::Bots)->requests, 'var(--group-bots)'],
                        [__('Your own traffic'), $period->group(TrafficGroup::Ours)->requests, 'var(--group-ours)'],
                    ] as [$name, $count, $colour])
                        <p class="flex items-center gap-1.5 text-[13px]">
                            <span class="size-2 rounded-full" style="background: {{ $colour }}"></span>
                            <span class="text-ink-2">{{ $name }}</span>
                            <span class="font-bold tabular-nums">{{ number_format($count) }}</span>
                            <span class="text-xs text-ink-3">{{ __('requests') }}</span>
                        </p>
                    @endforeach
                </div>
            </section>

            <p class="rounded-[10px] border border-rule bg-surface px-4 py-3 text-[13px] text-ink-2 shadow-card">
                {{ __('Server requests (every file: pages, images, scripts):') }} <span class="font-bold text-ink tabular-nums">{{ number_format($period->requests()) }}</span>.
            </p>

            <details class="rounded-[10px] border border-rule bg-surface shadow-card">
                <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                    <span class="font-bold">{{ __('Each day, in numbers') }}</span>
                    <span class="text-[13px] text-ink-3">{{ __(':whole whole of :days', ['whole' => $whole, 'days' => $period->length()]) }}</span>
                </summary>
                <div class="overflow-x-auto border-t border-rule">
                    <table class="w-full text-right text-[13px] tabular-nums">
                        <thead class="text-ink-2">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">{{ __('Day') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Visitors') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Pages read') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Visitors from AI') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('AI assistants') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Search engines') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Bots and scanners') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Requests') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-grid">
                            @foreach (array_reverse($period->days, true) as $date => $day)
                                <tr>
                                    <td class="px-4 py-1.5 text-left whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M') }}</td>
                                    @if ($day?->status === DayStatus::Verified)
                                        @foreach ([[TrafficGroup::People, 'readers'], [TrafficGroup::People, 'views']] as [$group, $figure])
                                            <td class="px-3 py-1.5">{{ number_format(SitePeriod::figureOf($day, $group, $figure)) }}</td>
                                        @endforeach
                                        <td class="px-3 py-1.5">{{ number_format($day->cited_visits) }}</td>
                                        @foreach ([TrafficGroup::AnswerEngines, TrafficGroup::SearchEngines, TrafficGroup::Bots] as $group)
                                            <td class="px-3 py-1.5">{{ number_format(SitePeriod::figureOf($day, $group, 'requests')) }}</td>
                                        @endforeach
                                        <td class="px-4 py-1.5">
                                            {{ number_format($day->hits) }}
                                            @if ($day->isPartial())
                                                <span class="text-xs text-ink-3">{{ __('(part of the day)') }}</span>
                                            @endif
                                        </td>
                                    @else
                                        <td colspan="7" @class(['px-4 py-1.5 text-left text-ink-3', 'text-bad' => $day?->status === DayStatus::Withheld])>
                                            {{ $day?->status->label() ?? __('Not recorded') }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    @endif
</x-layouts.app>
