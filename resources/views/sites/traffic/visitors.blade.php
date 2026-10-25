@php
    use App\Analytics\Labels;
    use App\Analytics\Tally\VisitJourneys;
    use App\Analytics\VisitStats;
    use App\Enums\CountKind;
    use App\Enums\SourceType;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $hasVisits = $period->hasVisitFigures();
    $hasReturning = $period->hasReturningFigures();
    $returning = $period->counts(CountKind::VisitorReturning);
    $new = $returning['new'] ?? 0;
    $back = $returning['returning'] ?? 0;
    $pagesChart = VisitStats::pagesChart($period->counts(CountKind::VisitPages));
    $lengthChart = VisitStats::lengthChart($period->counts(CountKind::VisitSeconds));
    $medianPages = VisitStats::median($period->counts(CountKind::VisitPages));
    $medianSeconds = VisitStats::median($period->counts(CountKind::VisitSeconds));
    $onePage = $period->counts(CountKind::VisitPages)['1'] ?? 0;
    $others = CountKind::OTHERS;
    $sourceLabel = fn (string $type) => $type === $others ? __('(all others)') : (SourceType::tryFrom($type)?->label() ?? $type);
    $percent = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100).'%' : '';
    $timezone = $site->timezone;
    $when = $period->length() === 1 ? __('yesterday') : __('in the last :count days', ['count' => $period->length()]);

    $exits = collect($period->top(CountKind::VisitExit, 50))->map(fn ($count, $path) => [
        'label' => $path === $others ? __('(all others)') : $path,
        'icon' => $path === $others ? null : Labels::pageIcon($path),
        'count' => $count,
    ])->values()->all();
    $steps = collect($period->top(CountKind::VisitStep, 50))->map(fn ($count, $step) => [
        'label' => $step === $others ? __('(all others)') : $step,
        'count' => $count,
    ])->values()->all();

    $bySource = $period->engagement('source');
    $byDevice = $period->engagement('device');
    $returningBySource = $period->counts(CountKind::ReturningSource);

    $chipLabel = fn (string $filter, string $value) => match ($filter) {
        'source' => SourceType::tryFrom($value)?->label() ?? $value,
        'country' => $value === 'Unknown' ? __('Not recorded') : $value,
        'visitor' => $value === 'returning' ? __('Returning') : __('New'),
        default => __($value),
    };
    $chipIcon = fn (string $filter, string $value) => match ($filter) {
        'source' => Labels::sourceIcon($value),
        'device' => Labels::deviceIcon($value),
        default => null,
    };
    $chipNames = ['source' => __('Source'), 'device' => __('Device'), 'country' => __('Country'), 'visitor' => __('Visitor')];
    $ownPeriod = $keptDays !== [] && count($keptDays) < $period->verified()->count();
    $keptFrom = $keptDays === [] ? null : \Carbon\CarbonImmutable::parse($keptDays[0]);
    $keptTo = $keptDays === [] ? null : \Carbon\CarbonImmutable::parse(end($keptDays));
@endphp

<x-layouts.app :title="__('Traffic')">
    <x-slot:toolbar>
        <x-period-switch :current="$choice" />
    </x-slot:toolbar>

    <x-traffic-tabs :team="$team" :site="$site" current="visitors" />

    <x-period-line :period="$period" />

    <div class="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 class="text-2xl font-bold tracking-tight">{{ __('Visitors') }}</h2>
        @if ($period->hasFigures())
            <p class="text-ink-2">
                @if ($hasReturning)
                    {{ __(':visits visits :when: :new by new visitors and :returning by returning ones.', ['visits' => number_format($people->readers), 'when' => $when, 'new' => number_format($new), 'returning' => number_format($back)]) }}
                @else
                    {{ __(':visits visits :when.', ['visits' => number_format($people->readers), 'when' => $when]) }}
                @endif
            </p>
        @endif
    </div>

    @if (! $period->hasFigures())
        <x-card :title="__('No figures yet')" :description="__('A day is counted once it is over where the site is, then counted a second time by the audit. Its figures show here only when both counts agree.')">
            <p class="text-ink-2">{{ __('The first figures appear the day after the site sends its first log lines.') }}</p>
        </x-card>
    @else
        <div class="grid gap-5">
            <p class="max-w-[92ch] text-[13px] text-ink-2">{{ __('Each visit here read at least one page: one browser, with no gap longer than 30 minutes. Arriving again from another outside source (an AI assistant, a search, another site, a campaign link) starts a new visit. Logs see what was loaded, not how long someone read, so a long read of one page shows as a short visit.') }}</p>

            {{-- Visitor flow --}}
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card" aria-labelledby="flow-title">
                <header class="mb-3">
                    <h3 id="flow-title" class="text-[15px] font-bold">
                        {{ __('Visitor flow') }}
                        @if ($ownPeriod)
                            <span class="ml-1 rounded-md bg-sunk px-1.5 py-0.5 text-xs font-medium text-ink-2" title="{{ __('Visits are kept :days days: the period above is longer', ['days' => config('analytics.journey_days')]) }}">{{ __(':from to :to · its own period', ['from' => $keptFrom->translatedFormat('j M'), 'to' => $keptTo->translatedFormat('j M')]) }}</span>
                        @endif
                    </h3>
                    <p class="max-w-[92ch] text-[13px] text-ink-2">{{ __('Where visits came from, the pages they read in order, and when they left. Band width is visits; hover a bar or a band for its figures.') }}</p>
                </header>

                <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px]">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-ink-2">{{ __('Start from') }}</span>
                        <nav class="inline-flex h-8 items-center rounded-lg border border-rule bg-surface p-0.5">
                            @foreach (['source' => __('Source type'), 'via' => __('Who sent them')] as $value => $label)
                                <a href="{{ request()->fullUrlWithQuery(['flow_start' => $value === 'source' ? null : $value]) }}" @class([
                                    'flex h-full items-center rounded-md px-2.5 no-underline',
                                    'bg-ink font-semibold text-surface' => $flow->start === $value,
                                    'text-ink-2 hover:text-ink' => $flow->start !== $value,
                                ])>{{ $label }}</a>
                            @endforeach
                        </nav>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-ink-2">{{ __('Pages') }}</span>
                        <nav class="inline-flex h-8 items-center rounded-lg border border-rule bg-surface p-0.5">
                            @foreach (\App\Analytics\VisitorFlow::DEPTHS as $depth)
                                <a href="{{ request()->fullUrlWithQuery(['flow_depth' => $depth === 4 ? null : $depth]) }}" @class([
                                    'flex h-full items-center rounded-md px-2.5 no-underline',
                                    'bg-ink font-semibold text-surface' => $flow->depth === $depth,
                                    'text-ink-2 hover:text-ink' => $flow->depth !== $depth,
                                ])>{{ $depth }}</a>
                            @endforeach
                        </nav>
                    </div>
                </div>

                @if ($flow->visits === 0)
                    <p class="py-3 text-ink-3">{{ __('No visit kept for this period: visits are kept :days days.', ['days' => config('analytics.journey_days')]) }}</p>
                @else
                    <x-charts.flow :flow="$flow" />
                    <p class="mt-2 max-w-[92ch] text-xs text-ink-3">{{ __(':count visits. Each visit once: where it came from, then its pages in order (a page loaded twice in a row counts once), then where it left. Each column keeps its 7 biggest values and folds the rest, so every column adds up to the visits.', ['count' => number_format($flow->visits)]) }}</p>
                @endif
            </section>

            {{-- Each visit --}}
            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card" aria-labelledby="visits-title">
                <header class="mb-3">
                    <h3 id="visits-title" class="text-[15px] font-bold">
                        {{ __('Each visit') }}
                        @if ($ownPeriod)
                            <span class="ml-1 rounded-md bg-sunk px-1.5 py-0.5 text-xs font-medium text-ink-2">{{ __(':from to :to · its own period', ['from' => $keptFrom->translatedFormat('j M'), 'to' => $keptTo->translatedFormat('j M')]) }}</span>
                        @endif
                    </h3>
                    <p class="text-[13px] text-ink-2">{{ __('Newest first, one row per visit. Anonymous: no address, no browser string, kept :days days.', ['days' => config('analytics.journey_days')]) }}</p>
                </header>

                <div class="mb-3 grid gap-1.5 text-[13px]">
                    @foreach ($chips as $filter => $values)
                        @if ($values !== [])
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="w-16 flex-none text-ink-2">{{ $chipNames[$filter] }}</span>
                                @foreach ($values as $value => $count)
                                    @php($active = ($filters[$filter] ?? null) === (string) $value)
                                    <a href="{{ request()->fullUrlWithQuery([$filter => $active ? null : $value, 'all' => null]) }}" @class([
                                        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 no-underline',
                                        'border-ink bg-ink text-surface' => $active,
                                        'border-rule bg-surface text-ink hover:border-ink-3' => ! $active,
                                    ]) @if ($active) aria-current="true" @endif>
                                        @if ($filter === 'country' && Labels::flag((string) $value) !== '')
                                            <span aria-hidden="true">{{ Labels::flag((string) $value) }}</span>
                                        @elseif ($chipIcon($filter, (string) $value))
                                            <x-icon :name="$chipIcon($filter, (string) $value)" class="size-3.5" />
                                        @endif
                                        {{ $chipLabel($filter, (string) $value) }}
                                        <span @class(['tabular-nums', 'text-ink-3' => ! $active])>{{ number_format($count) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>

                <form method="GET" class="mb-3 flex items-center gap-2">
                    @foreach (request()->except(['q', 'all']) as $name => $value)
                        @if (is_string($value))
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search pages and sources') }}" class="h-8 w-72 max-w-full rounded-lg border border-rule bg-surface px-2.5 text-[13px] focus:border-ink-3 focus:outline-none">
                    @if ($filters !== [] || $search !== '')
                        <a href="{{ request()->fullUrlWithQuery(['source' => null, 'device' => null, 'country' => null, 'visitor' => null, 'q' => null, 'all' => null]) }}" class="text-[13px] text-ink-2 underline hover:text-ink">{{ __('Clear the filters') }}</a>
                    @endif
                </form>

                <div class="overflow-x-auto rounded-lg border border-rule">
                    <table class="w-full min-w-[980px] text-[13px] tabular-nums">
                        <thead class="border-b border-rule bg-sunk/50 text-left text-ink-2">
                            <tr>
                                <th class="px-3 py-2 font-semibold">{{ __('When (:timezone)', ['timezone' => $timezone]) }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Country') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('City') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Device') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Source') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Visitor') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Pages') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Time on site') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Pages in order') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-grid">
                            @forelse ($visits as $visit)
                                @php($started = $visit->started_at->setTimezone($timezone))
                                <tr class="align-top">
                                    <td class="px-3 py-2">
                                        <span class="font-semibold">{{ $started->format('H:i') }}</span>
                                        <span class="block text-xs text-ink-3">{{ $started->translatedFormat('D j M') }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if ($visit->country === 'Unknown')
                                            <span class="text-ink-3">{{ __('Not recorded') }}</span>
                                        @else
                                            <span aria-hidden="true">{{ Labels::flag($visit->country) }}</span> {{ $visit->country }}
                                        @endif
                                    </td>
                                    <td @class(['px-3 py-2', 'text-ink-3' => $visit->city === ''])>{{ $visit->city !== '' ? $visit->city : __('Not recorded') }}</td>
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-1.5"><x-icon :name="Labels::deviceIcon($visit->device)" class="size-3.5 text-ink-2" />{{ __($visit->device) }}</span>
                                        <span class="block text-xs text-ink-3">{{ $visit->browser }}, {{ $visit->system }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-1.5">
                                            <x-icon :name="Labels::sourceIcon($visit->source)" class="size-3.5 text-ink-2" />
                                            {{ $visit->via !== '' ? $visit->via : (SourceType::tryFrom($visit->source)?->label() ?? $visit->source) }}
                                        </span>
                                        @if ($visit->via !== '' || $visit->campaign !== '')
                                            <span class="block text-xs text-ink-3">{{ collect([$visit->via !== '' ? (SourceType::tryFrom($visit->source)?->label() ?? $visit->source) : null, $visit->campaign !== '' ? $visit->campaign : null])->filter()->implode(', ') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($visit->returning === null)
                                            <span class="text-ink-3">—</span>
                                        @else
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                                'bg-[color-mix(in_srgb,var(--series-visitors)_14%,transparent)] text-[var(--series-visitors)]' => ! $visit->returning,
                                                'bg-sunk text-ink-2' => $visit->returning,
                                            ])>{{ $visit->returning ? __('Returning') : __('New') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ number_format($visit->pages) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ VisitStats::words($visit->seconds) }}</td>
                                    <td class="max-w-[420px] px-3 py-2">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate font-mono text-xs" title="{{ implode(VisitJourneys::ARROW, $visit->trail) }}">
                                                <strong>{{ $visit->trail[0] ?? '' }}</strong>@foreach (array_slice($visit->trail, 1) as $page)<span class="text-ink-3"> › </span>{{ $page }}@endforeach
                                            </span>
                                            @if ($visit->not_found)
                                                <span class="flex-none rounded border border-bad px-1 text-[11px] font-semibold text-bad" title="{{ __('met a page not found (404)') }}">404</span>
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-4 text-ink-3">{{ $filters !== [] || $search !== '' ? __('No visit matches the filters.') : __('No visit kept for this period: visits are kept :days days.', ['days' => config('analytics.journey_days')]) }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-2 flex items-center justify-between text-[13px] text-ink-3">
                    <span>{{ trans_choice(':count row|:count rows', $matching, ['count' => number_format($matching)]) }} <x-geoip-credit class="mt-1" /></span>
                    @if ($matching > $visits->count())
                        <x-button variant="secondary" size="sm" :href="request()->fullUrlWithQuery(['all' => 1])">{{ __('Show all :count', ['count' => number_format(min($matching, \App\Http\Controllers\SiteTrafficController::VISITS_MAX))]) }}</x-button>
                    @endif
                </div>
            </section>

            @unless ($hasVisits)
                <x-notice>{{ __('Some days of this period were counted before visits were recorded: their visit details are not shown, so no figure below leaves days out.') }}</x-notice>
            @else
                {{-- New and returning, how engaged --}}
                <div class="grid items-start gap-4 lg:grid-cols-2">
                    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                        <h3 class="text-[15px] font-bold">{{ __('New and returning visitors') }}</h3>
                        <p class="mb-3 text-[13px] text-ink-2">{{ __('Each visit, by whether its visitor was seen on an earlier day in the :days before (a keyed hash of the address and browser, kept :days days). Counted per visit, so two visits on a first day are both new.', ['days' => config('analytics.returning_days')]) }}</p>
                        @if ($hasReturning)
                            <div class="mb-2 flex gap-8">
                                @foreach ([[__('New'), $new, 'var(--series-visitors)'], [__('Returning'), $back, 'var(--ink-2)']] as [$label, $count, $color])
                                    <div>
                                        <p class="flex items-center gap-1.5 text-[13px] font-semibold text-ink-2"><span class="size-2.5 rounded-[3px]" style="background: {{ $color }}"></span>{{ $label }}</p>
                                        <p><span class="text-3xl font-bold tabular-nums">{{ number_format($count) }}</span> <span class="text-[13px] text-ink-3">{{ $percent($count, $new + $back) }}</span></p>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex h-3 overflow-hidden rounded-full bg-sunk">
                                <span style="width: {{ $new + $back > 0 ? $new / ($new + $back) * 100 : 0 }}%; background: var(--series-visitors)"></span>
                                <span style="width: {{ $new + $back > 0 ? $back / ($new + $back) * 100 : 0 }}%; background: var(--ink-2)"></span>
                            </div>
                        @else
                            <p class="text-ink-3">{{ __('Not recorded for every day of this period.') }}</p>
                        @endif
                    </section>

                    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                        <h3 class="text-[15px] font-bold">{{ __('How engaged visits were') }}</h3>
                        <p class="mb-3 text-[13px] text-ink-2">{{ __('Each chart adds up to the :count visits. Hover a bar for its share.', ['count' => number_format($people->readers)]) }}</p>
                        <div class="grid grid-cols-2 gap-5">
                            @foreach ([[__('Pages per visit'), $pagesChart, 'var(--series-pages)'], [__('Time on site'), $lengthChart, 'var(--series-visitors)']] as [$title, $chart, $color])
                                @php($tallest = max(1, ...array_values($chart)))
                                <figure>
                                    <figcaption class="mb-2 text-[13px] font-semibold">{{ $title }}</figcaption>
                                    <div class="flex h-28 items-end gap-1.5">
                                        @foreach ($chart as $label => $count)
                                            <div class="flex h-full flex-1 flex-col justify-end" title="{{ $label }}: {{ trans_choice(':count visit|:count visits', $count) }} ({{ $percent($count, $people->readers) }})">
                                                <span class="mb-0.5 text-center text-[11px] font-semibold tabular-nums">{{ number_format($count) }}</span>
                                                <span class="block rounded-t-[3px]" style="height: {{ max($count > 0 ? 2 : 0, $count / $tallest * 100) }}%; background: {{ $color }}"></span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-1 flex gap-1.5 border-t border-rule pt-1">
                                        @foreach (array_keys($chart) as $label)
                                            <span class="flex-1 text-center text-[10.5px] leading-tight text-ink-3">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                </figure>
                            @endforeach
                        </div>
                        <p class="mt-3 text-[13px] text-ink-2">{{ __('Typical visit (the median): :pages over :length. :share of visits read one page and left.', ['pages' => trans_choice(':count page|:count pages', (int) $medianPages), 'length' => VisitStats::words($medianSeconds), 'share' => $percent($onePage, $people->readers)]) }}</p>
                        <p class="mt-1 text-xs text-ink-3">{{ __('Time on site runs from the first request to the last: a log sees what was loaded, not how long someone read, so a long read of one page shows as a short visit.') }}</p>
                    </section>
                </div>

                {{-- Where visits ended, next steps --}}
                <div class="grid items-start gap-4 lg:grid-cols-2">
                    <x-top-list :title="__('Where visits ended')" :unit="__('The last page a visitor was seen on')" :rows="$exits" :total="$people->readers" tint="var(--series-pages)" :shown="10" />
                    <x-top-list :title="__('Common next steps')" :unit="__('From one page to the next')" :rows="$steps" :total="array_sum($period->counts(CountKind::VisitStep))" tint="var(--ink-3)" :shown="10" />
                </div>

                {{-- Engagement by source and device --}}
                <section class="grid gap-3">
                    <header>
                        <h3 class="text-[15px] font-bold">{{ __('Engagement by source and device') }}</h3>
                        <p class="text-[13px] text-ink-2">{{ __('Each visit by where it came from and its device. A rate is given over 10 visits or more.') }}</p>
                    </header>
                    <div class="grid items-start gap-4 lg:grid-cols-2">
                        @foreach ([[__('By source'), __('The source of each visit\'s first page'), __('Source'), $bySource, 'source'], [__('By device'), __('The device each visit used'), __('Device'), $byDevice, 'device']] as [$title, $unit, $column, $rows, $dimension])
                            <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
                                <h4 class="text-[14px] font-bold">{{ $title }}</h4>
                                <p class="mb-3 text-xs text-ink-3">{{ $unit }}</p>
                                <table class="w-full text-[13px] tabular-nums">
                                    <thead class="border-b border-rule text-ink-2">
                                        <tr>
                                            <th class="py-2 text-left font-semibold">{{ $column }}</th>
                                            <th class="py-2 text-right font-semibold">{{ __('Visits') }}</th>
                                            <th class="py-2 text-right font-semibold">{{ __('Pages per visit') }}</th>
                                            <th class="py-2 text-right font-semibold">{{ __('One-page') }}</th>
                                            <th class="py-2 text-right font-semibold">{{ __('Typical length') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-grid">
                                        @foreach ($rows as $name => $row)
                                            @php($rated = $row['visits'] >= 10)
                                            <tr>
                                                <td class="py-1.5">
                                                    <span class="flex items-center gap-1.5">
                                                        <x-icon :name="$dimension === 'source' ? Labels::sourceIcon($name) : Labels::deviceIcon($name)" class="size-3.5 text-ink-2" />
                                                        {{ $dimension === 'source' ? $sourceLabel($name) : __($name) }}
                                                    </span>
                                                </td>
                                                <td class="py-1.5 text-right">{{ number_format($row['visits']) }}</td>
                                                <td class="py-1.5 text-right">{{ $rated ? number_format($row['views'] / $row['visits'], 1) : '' }}</td>
                                                <td class="py-1.5 text-right">{{ $rated ? $percent($row['one_page'], $row['visits']) : '' }}</td>
                                                <td class="py-1.5 text-right">{{ $rated ? VisitStats::words(VisitStats::median($row['seconds'])) : '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @php($blank = collect($rows)->filter(fn ($row) => $row['visits'] < 10)->count())
                                @if ($blank > 0)
                                    <p class="mt-2 text-xs text-ink-3">{{ trans_choice('A blank rate: this row has fewer than 10 visits, too few to say.|A blank rate: :count rows have fewer than 10 visits, too few to say.', $blank) }}</p>
                                @endif
                            </section>
                        @endforeach
                    </div>
                </section>

                {{-- New and returning by source --}}
                @if ($hasReturning)
                    <section class="grid gap-3">
                        <header>
                            <h3 class="text-[15px] font-bold">{{ __('New and returning by source') }}</h3>
                            <p class="text-[13px] text-ink-2">{{ __('Each visit, labelled by its own source. Returning: the same visitor (a keyed hash, kept :days days) was seen on an earlier day in the :days before.', ['days' => config('analytics.returning_days')]) }}</p>
                        </header>
                        <div class="overflow-hidden rounded-[10px] border border-rule bg-surface shadow-card">
                            <table class="w-full text-[13px] tabular-nums">
                                <thead class="border-b border-rule text-ink-2">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-semibold">{{ __('Source') }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ __('Visits') }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ __('New') }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ __('Returning') }}</th>
                                        <th class="px-4 py-2 text-right font-semibold">{{ __('Returning share') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-grid">
                                    @foreach ($bySource as $name => $row)
                                        @php($came = $returningBySource[$name] ?? 0)
                                        <tr>
                                            <td class="px-4 py-1.5">
                                                <span class="flex items-center gap-1.5"><x-icon :name="Labels::sourceIcon($name)" class="size-3.5 text-ink-2" />{{ $sourceLabel($name) }}</span>
                                            </td>
                                            <td class="px-4 py-1.5 text-right">{{ number_format($row['visits']) }}</td>
                                            <td class="px-4 py-1.5 text-right">{{ number_format($row['visits'] - $came) }}</td>
                                            <td class="px-4 py-1.5 text-right">{{ number_format($came) }}</td>
                                            <td class="px-4 py-1.5 text-right">{{ $percent($came, $row['visits']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            @endunless
        </div>
    @endif
</x-layouts.app>
