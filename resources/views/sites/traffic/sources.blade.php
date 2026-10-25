@php
    use App\Analytics\Labels;
    use App\Analytics\Sources;
    use App\Enums\CountKind;
    use App\Enums\SourceType;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $others = CountKind::OTHERS;
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';

    // Most first, the folded tail last.
    $sorted = function (array $counts, array $leaveOut = []) use ($others) {
        $counts = array_diff_key($counts, array_flip($leaveOut));
        $tail = $counts[$others] ?? null;
        unset($counts[$others]);
        arsort($counts);

        return $tail === null ? $counts : [...$counts, $others => $tail];
    };
    $named = fn (string $name, string $label) => $name === $others ? __('(all others)') : $label;

    $lists = [
        'type' => [__('Source type'), $sorted($period->counts(CountKind::PeopleSource)),
            fn (string $name) => ['label' => $named($name, SourceType::tryFrom($name)?->label() ?? $name), 'icon' => $name === $others ? null : Labels::sourceIcon($name)]],
        'social' => [__('Social network'), $sorted($period->counts(CountKind::PeopleSocial)), fn (string $name) => ['label' => $named($name, $name)]],
        'referrer' => [__('Referring site'), $sorted($period->counts(CountKind::PeopleReferrer), [Sources::NO_REFERRER, Sources::THIS_SITE]), fn (string $name) => ['label' => $named($name, $name)]],
        'campaign' => [__('Campaign'), $sorted($period->counts(CountKind::PeopleCampaign), [Sources::NO_CAMPAIGN]), fn (string $name) => ['label' => $named($name, $name), 'mono' => true]],
    ];
    [$listLabel, $listCounts, $listCell] = $lists[$list];
    $listRows = collect($listCounts)->map(fn ($count, $name) => [$listCell((string) $name), number_format($count), $percent($count, $people->readers)])->values()->all();

    $engagementColumns = fn (string $first) => [[$first, 'left'], [__('Visits'), 'right'], [__('Pages per visit'), 'right'], [__('Broken links met'), 'right'], [__('Form sends'), 'right']];
    $engagementCells = fn (array $row) => [
        number_format($row['visits']),
        number_format($row['views'] / max(1, $row['visits']), 1),
        number_format($row['not_found']),
        number_format($row['posted']),
    ];
    $utmNames = ['source' => __('Source'), 'medium' => __('Medium'), 'campaign' => __('Campaign'), 'term' => __('Term'), 'content' => __('Content')];
    $utmRows = collect($period->engagement("utm_{$utm}"))->map(fn ($row, $name) => [['label' => (string) $name, 'mono' => true], ...$engagementCells($row)])->values()->all();

    // The pages of each referring site that sent visitors, most first.
    $referringPages = [];
    foreach ($period->counts(CountKind::PeopleReferrerPage) as $page => $count) {
        $host = strtok((string) $page, '/');
        $referringPages[$host][substr((string) $page, strlen($host)) ?: '/'] = $count;
    }
    $referrers = collect($period->engagement('referrer'))->except([Sources::NO_REFERRER, Sources::THIS_SITE]);
@endphp

<x-section-frame :team="$team" :site="$site" current="sources" :title="__('Sources')" :period="$period" :choice="$choice"
    :lede="__('Visitors by source type, social network, referring site or campaign: each list is its own, and counts each visit once, by where its first page came from.')">

    <section class="grid gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <x-segmented name="list" :current="$list" default="type" :options="collect($lists)->map(fn ($list) => $list[0].' · '.number_format(count($list[1])))->all()" />
        </div>

        <x-rows-table :columns="[[$listLabel, 'left'], [__('Visitors'), 'right'], [__('Share'), 'right']]" :rows="$listRows" :shown="15">
            <p class="mt-2 text-xs text-ink-3">{{ __('Share is of all :count visitors of the period.', ['count' => number_format($people->readers)]) }}
                {{ __('The pages these visitors landed on are on') }} <a href="{{ route('teams.sites.traffic.pages', [$team, $site, 'days' => $choice]) }}" class="underline hover:text-ink">{{ __('Pages') }}</a>.</p>
        </x-rows-table>
    </section>

    <section class="grid gap-3">
        <header>
            <h3 class="text-[15px] font-bold">{{ __('Campaigns and referrers') }}</h3>
            <p class="text-[13px] text-ink-2">{{ __('How visits went, by the utm_ tags of their first page, and by the site that sent them. Broken links met: visits that hit a page not found (404). Form sends: visits that sent a form or another POST.') }}</p>
        </header>

        @if (! $period->hasVisitFigures())
            <x-notice>{{ __('Some days of this period were counted before visits were recorded: these tables would leave them out, so they are not shown.') }}</x-notice>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[13px] font-semibold text-ink-2">{{ __('UTM tags') }}</span>
                <x-segmented name="utm" :current="$utm" default="source" :options="$utmNames" />
            </div>
            <x-rows-table :columns="$engagementColumns('utm_'.$utm)" :rows="$utmRows" :shown="10" :empty="__('No visit with this tag in this period.')" />

            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card" data-top-list>
                <header class="mb-2 flex items-baseline justify-between gap-3">
                    <div>
                        <h3 class="text-[14px] font-bold">{{ __('Referring sites, down to the page') }}</h3>
                        <p class="text-xs text-ink-3">{{ __('Open a site to see the pages on it that sent visitors. Only the referrer\'s address is kept, never anything about the visitor.') }}</p>
                    </div>
                    @if ($referrers->count() > 15)
                        <button type="button" class="cursor-pointer text-[13px] text-ink-2 hover:text-ink" data-see-all data-less="{{ __('Show less') }}">{{ __('See all :count', ['count' => $referrers->count()]) }}</button>
                    @endif
                </header>
                @if ($referrers->isEmpty())
                    <p class="py-3 text-ink-3">{{ __('No visit came from another site in this period.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-[13px] tabular-nums">
                            <thead class="border-b border-rule text-ink-2">
                                <tr>
                                    @foreach ($engagementColumns(__('Referring site')) as [$label, $align])
                                        <th @class(['py-2 pr-3 font-semibold last:pr-0', 'text-left' => $align === 'left', 'text-right' => $align === 'right'])>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-grid">
                                @foreach ($referrers as $host => $row)
                                    @php($pages = $referringPages[$host] ?? [])
                                    @php(arsort($pages))
                                    <tr @class(['align-top', 'hidden' => $loop->index >= 15]) @if ($loop->index >= 15) data-extra @endif>
                                        <td class="py-1.5 pr-3">
                                            @if ($pages === [])
                                                {{ $host }}
                                            @else
                                                <details>
                                                    <summary class="cursor-pointer">{{ $host }}</summary>
                                                    <ul class="mt-1 grid gap-0.5 pl-4 text-xs text-ink-2">
                                                        @foreach (array_slice($pages, 0, 20, true) as $path => $count)
                                                            <li class="flex justify-between gap-4"><span class="truncate font-mono">{{ $path }}</span><span class="tabular-nums">{{ number_format($count) }}</span></li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
                                        </td>
                                        @foreach ($engagementCells($row) as $cell)
                                            <td class="py-1.5 pr-3 text-right last:pr-0">{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </section>
</x-section-frame>
