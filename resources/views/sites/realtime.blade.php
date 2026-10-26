@php
    use App\Analytics\Labels;
    use App\Enums\SourceType;

    $local = fn (int $time) => \Carbon\CarbonImmutable::createFromTimestamp($time, $live->timezone)->format('H:i');
    $rows = fn (array $counts, callable $map) => collect($counts)->map($map)->values()->all();

    $pages = $rows($live->list('pages'), fn ($count, $path) => ['label' => $path, 'icon' => Labels::pageIcon($path), 'count' => $count]);
    $sources = $rows($live->list('sources'), fn ($count, $type) => ['label' => SourceType::tryFrom($type)?->label() ?? $type, 'icon' => Labels::sourceIcon($type), 'count' => $count]);
    $devices = $rows($live->list('devices'), fn ($count, $device) => ['label' => __($device), 'icon' => Labels::deviceIcon($device), 'count' => $count]);
    $engines = $rows($live->list('engines'), fn ($count, $name) => ['label' => $name, 'tile' => Labels::owner($name), 'note' => Labels::isUserFetcher($name) ? __('user fetcher') : null, 'count' => $count]);
    $search = $rows($live->list('search'), fn ($count, $name) => ['label' => $name, 'tile' => Labels::owner($name), 'count' => $count]);
@endphp

<x-layouts.app :title="__('Overview')">
    <x-slot:toolbar>
        <span class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[color-mix(in_srgb,var(--good)_40%,var(--rule))] bg-surface px-3 text-[13px] font-semibold">
            <span class="size-2 rounded-full bg-good"></span> {{ __('Today') }} · {{ __('Live') }}
        </span>
        <x-button variant="secondary" size="sm" :href="route('teams.sites.show', [$team, $site])">{{ __('Whole days') }}</x-button>
    </x-slot:toolbar>

    <x-overview-tabs :team="$team" :site="$site" current="realtime" />

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3 text-[13px] text-ink-2" data-live-refresh="120">
        <p>
            <span class="font-semibold text-ink">{{ __('Now') }}</span> ·
            {{ __('Counted at :time (:timezone), today\'s log so far, with the same rules as the whole days.', ['time' => $live->asOf->setTimezone($live->timezone)->format('H:i:s'), 'timezone' => $live->timezone]) }}
            <span data-live-state>{{ __('Refreshed every 2 minutes.') }}</span>
        </p>
        <button type="button" class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-rule bg-surface px-3 font-semibold text-ink hover:border-ink-3" data-live-pause data-pause="{{ __('Pause') }}" data-resume="{{ __('Resume') }}" data-paused="{{ __('Paused.') }}" data-running="{{ __('Refreshed every 2 minutes.') }}">
            {{ __('Pause') }}
        </button>
    </div>

    <div class="grid gap-5">
        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-[10px] border border-rule bg-rule shadow-card lg:grid-cols-4">
            @foreach ([
                [__('Active now'), $live->activeNow, __('visitors, last 5 minutes'), __('Visitors with a request in the last 5 minutes.')],
                [__('Last 30 minutes'), $live->recentVisitors, __('visitors with a request in them'), __('Visitors whose last request is less than 30 minutes old.')],
                [__('Visitors today'), $live->visitorsToday, $live->signedInToday > 0
                    ? __(':count signed-in users so far, :recent in the last 30 min', ['count' => number_format($live->signedInToday), 'recent' => number_format($live->signedInRecent)])
                    : __('so far, the same count as Overview\'s'), null],
                [__('Pages read, 30 min'), $live->pagesRecent, __('page views by visitors'), null],
            ] as [$label, $value, $note, $info])
                <div class="bg-surface px-4 py-3">
                    <p class="flex items-center gap-1.5 text-[13px] font-semibold text-ink-2">
                        {{ $label }}
                        @if ($info)
                            <x-info :text="$info" />
                        @endif
                    </p>
                    <p class="mt-0.5 text-[30px] leading-tight font-bold tracking-tight tabular-nums">{{ number_format($value) }}</p>
                    <p class="text-xs text-ink-3">{{ $note }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('Visitors on the site, minute by minute') }}</h3>
                <p class="mb-2 text-xs text-ink-3">{{ __('a visitor is on the site from their first request to their last; the last minute is still running') }}</p>
                <x-charts.minute-bars :values="$live->visitorsByMinute" :first-minute="$live->firstMinute" :timezone="$live->timezone" color="var(--series-visitors)" :unit="__('visitors')" />
            </section>
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('Requests per minute') }}</h3>
                <p class="mb-2 text-xs text-ink-3">{{ __('every request the server logged: pages, files, bots and people alike. Not visitors.') }}</p>
                <x-charts.minute-bars :values="$live->requestsByMinute" :first-minute="$live->firstMinute" :timezone="$live->timezone" color="var(--ink-3)" :unit="__('requests')" />
            </section>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('Where visitors are') }}</h3>
                <p class="mb-2 text-xs text-ink-3">{{ __('countries shaded by visits today; a pulsing bubble on each country with someone active in the last 30 minutes') }}</p>
                <x-charts.world-map :countries="collect($live->countries)->map(fn ($figures) => ['count' => $figures['today'], 'active' => $figures['recent']])->all()"
                    :label="__('World map: visits today by country, and the countries with visitors active in the last 30 minutes')"
                    :legend="__('Visits today')" :count="__(':count visit today|:count visits today')" :active="__(':count active in the last 30 minutes|:count active in the last 30 minutes')" live />
            </section>

            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('By country') }}</h3>
                <p class="mb-2 text-xs text-ink-3">{{ __('visits today, and how many were active in the last 30 minutes') }}</p>
                @if ($live->countries === [])
                    <p class="py-3 text-ink-3">{{ __('No visitor yet today.') }}</p>
                @else
                    <table class="w-full text-[13px] tabular-nums">
                        <thead class="border-b border-rule text-ink-2">
                            <tr>
                                <th class="py-2 text-left font-semibold">{{ __('Country') }}</th>
                                <th class="py-2 text-right font-semibold">{{ __('Visits today') }}</th>
                                <th class="py-2 text-right font-semibold">{{ __('Active, 30 min') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-grid">
                            @foreach (array_slice($live->countries, 0, 10, true) as $code => $figures)
                                <tr>
                                    <td class="py-1.5">
                                        <span class="mr-1.5" aria-hidden="true">{{ Labels::flag($code) }}</span>{{ $code === 'Unknown' ? __('Not recorded') : $code }}
                                    </td>
                                    <td class="py-1.5 text-right">{{ number_format($figures['today']) }}</td>
                                    <td class="py-1.5 text-right">{{ number_format($figures['recent']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-ink-3">{{ __('A country comes from the log\'s own country field (the extended nginx format), else from the visitor\'s address; without either, it is not recorded.') }}</p>
                    <x-geoip-credit class="mt-1" />
                @endif
            </section>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                <h3 class="text-[14px] font-bold">{{ __('Newest visits') }}</h3>
                <p class="mb-2 text-xs text-ink-3">{{ __('times in :timezone, newest first; no address, no browser string', ['timezone' => $live->timezone]) }}</p>
                @if ($live->newest === [])
                    <p class="py-3 text-ink-3">{{ __('No visitor yet today.') }}</p>
                @else
                    <ol class="divide-y divide-grid">
                        @foreach ($live->newest as $visit)
                            <li class="grid grid-cols-[3rem_1.5rem_1fr] gap-x-1 py-2 text-[13px]">
                                <span class="text-ink-3 tabular-nums">{{ $local($visit['started']) }}</span>
                                <span aria-hidden="true">{{ Labels::flag($visit['country']) }}</span>
                                <span>
                                    {{ $visit['country'] === 'Unknown' ? '' : $visit['country'].', ' }}{{ __($visit['device']) }}
                                    {{ $visit['from'] ? __('from :source', ['source' => $visit['from']]) : '· '.__('Direct') }}:
                                    {{ trans_choice(':count page so far|:count pages so far', $visit['pages']) }}
                                    @if ($visit['signed_in'] ?? false)
                                        <span class="ml-1 rounded-full bg-sunk px-1.5 text-[11px] text-ink-2">{{ __('signed in') }}</span>
                                    @endif
                                    @if ($visit['active'] === 'now')
                                        <span class="ml-1 rounded-full border border-[color-mix(in_srgb,var(--good)_40%,var(--rule))] px-1.5 text-[11px] text-good">{{ __('active now') }}</span>
                                    @elseif ($visit['active'] === 'recent')
                                        <span class="ml-1 rounded-full border border-rule px-1.5 text-[11px] text-ink-3">{{ __('active, 30 min') }}</span>
                                    @endif
                                    <span class="block text-ink-2">{{ __('landed on') }} <code class="font-mono text-[12.5px] text-ink">{{ $visit['landing'] }}</code></span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <div class="grid gap-4">
                <x-top-list :title="__('Pages being read')" :unit="__('page views by visitors in the last 30 minutes (:count)', ['count' => $live->pagesRecent])" :rows="$pages" :total="$live->pagesRecent" :shown="8" />
                <x-top-list :title="__('Sources now')" :unit="__('visitors active in the last 30 minutes, by where they came from')" :rows="$sources" :total="$live->recentVisitors" :shown="8" />
                <x-top-list :title="__('Devices now')" :unit="__('visitors active in the last 30 minutes, by device')" :rows="$devices" :total="$live->recentVisitors" />

                <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                    <h3 class="text-[14px] font-bold">{{ __('AI and crawlers now') }}</h3>
                    <p class="mb-2 text-xs text-ink-3">{{ __('requests in the last 30 minutes: machines, never visitors') }}</p>

                    <p class="mb-1 text-xs font-semibold text-ink-3">{{ __('AI assistants') }}</p>
                    <x-top-list bare :title="''" :unit="''" :rows="$engines" :total="array_sum($live->list('engines'))" tint="var(--group-answer_engines)" :shown="8" />

                    <p class="mt-3 mb-1 text-xs font-semibold text-ink-3">{{ __('Search crawlers') }}</p>
                    <x-top-list bare :title="''" :unit="''" :rows="$search" :total="array_sum($live->list('search'))" tint="var(--group-search_engines)" :shown="8" />

                    <p class="mt-3 flex items-center gap-1.5 text-[13px]">
                        <span class="size-2 rounded-full bg-[var(--group-bots)]"></span>
                        {{ __('Other bots and scanners:') }} <span class="font-bold tabular-nums">{{ number_format($live->bots) }}</span> {{ __('requests') }}
                    </p>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
