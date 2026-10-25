@php
    use App\Analytics\Insights\InsightWords;
    use App\Analytics\Insights\Insights;

    $windows = ['1' => __('Yesterday'), '7' => __('7 days'), '30' => __('30 days')];
    $says = [
        '1' => __('Yesterday against the 14 days before it, and what was new yesterday.'),
        '7' => __('The last 7 whole days against the 7 before them.'),
        '30' => __('The last 30 whole days against the 30 before them, and pages worth fixing over 30 days.'),
    ];
    $top = 5;
@endphp

<x-layouts.app :title="__('Overview')">
    <x-overview-tabs :team="$team" :site="$site" current="insights" />

    <div class="mb-4">
        <h2 class="flex items-center gap-1.5 text-2xl font-bold tracking-tight">
            {{ __('Insights') }}
            <x-info :text="__('What changed, in plain words: what is working, what dropped, what is new or gone, what looks unusual, and what is worth fixing. Each compares whole, verified days only. The five with the most impact come first; a finding on small numbers counts less.')" />
        </h2>
        <p class="mt-1 text-[13px] text-ink-2">{{ __('Whole days, compared with the days before.') }}</p>
    </div>

    <div class="grid gap-5">
        <div class="grid gap-2">
            <nav class="inline-flex h-8 items-center justify-self-start rounded-lg border border-rule bg-surface p-0.5 text-[13px]" aria-label="{{ __('Compare') }}">
                @foreach ($windows as $value => $label)
                    <a href="{{ request()->fullUrlWithQuery(['window' => $value]) }}" @class([
                        'flex h-full items-center gap-1.5 rounded-md px-2.5 no-underline',
                        'bg-ink font-semibold text-surface' => $window === $value,
                        'text-ink-2 hover:text-ink' => $window !== $value,
                    ]) @if ($window === $value) aria-current="true" @endif>
                        {{ $label }}
                        @if ($counts[$value] > 0)
                            <span class="tabular-nums opacity-75">{{ $counts[$value] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <p class="text-[13px] text-ink-2">{{ $says[$window] }}</p>
        </div>

        @if ($cards === [])
            <x-notice tone="good">
                {{ array_sum($counts) > 0
                    ? __('Nothing passes the thresholds for this window. That is a quiet period, not missing data.')
                    : __('No insights yet: they need two complete periods of whole, verified days to compare, and :days days of history for what is new.', ['days' => Insights::MIN_HISTORY_DAYS]) }}
            </x-notice>
        @else
            <section class="grid gap-2" data-top-list>
                <h3 class="text-[15px] font-bold">{{ count($cards) > $top ? __('The :count biggest', ['count' => $top]) : __('What changed') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cards as $index => $card)
                        @php
                            $finding = $card['lead'];
                            [$label, $tone, $icon] = InsightWords::type($finding['type']);
                            [$number, $words] = InsightWords::headline($finding);
                        @endphp
                        <article @class(['flex flex-col gap-1.5 rounded-[10px] border border-rule border-t-[3px] bg-surface p-4 shadow-card', 'hidden' => $index >= $top]) style="border-top-color: {{ $tone }}" @if ($index >= $top) data-extra @endif>
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-ink-2"><x-icon :name="$icon" class="size-3.5" style="color: {{ $tone }}" />{{ $label }}</p>
                            <p class="font-semibold">{{ InsightWords::sentence($finding) }}</p>
                            <p class="flex items-baseline gap-1.5">
                                <span class="text-2xl font-bold tabular-nums">{{ number_format($number) }}</span>
                                <span class="text-xs text-ink-3">{{ $words }}</span>
                            </p>
                            <a href="{{ InsightWords::href($finding, $site) }}" class="mt-auto inline-flex items-center gap-1 text-[13px] font-semibold text-ink underline decoration-rule underline-offset-2 hover:decoration-ink" title="{{ \Carbon\CarbonImmutable::parse($finding['from'])->translatedFormat('j M') }} – {{ \Carbon\CarbonImmutable::parse($finding['to'])->translatedFormat('j M') }}">
                                {{ __('Open in :tab', ['tab' => InsightWords::target($finding)[1]]) }} <x-icon name="chevron-right" class="size-3.5" />
                            </a>
                            @if ($card['more'] !== [])
                                <details class="text-[13px] text-ink-2">
                                    <summary class="cursor-pointer">{{ trans_choice(':count more about this|:count more about this', count($card['more'])) }}</summary>
                                    <ul class="mt-1 grid gap-1 pl-4">
                                        @foreach ($card['more'] as $more)
                                            <li class="list-disc">{{ InsightWords::sentence($more) }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </article>
                    @endforeach
                </div>
                @if (count($cards) > $top)
                    <x-button type="button" variant="secondary" size="sm" class="justify-self-start" data-see-all :data-less="__('Show the :count biggest only', ['count' => $top])">{{ __('Show all :count', ['count' => count($cards)]) }}</x-button>
                @endif
            </section>

            @if (count($findings) > count($cards))
                <p class="text-xs text-ink-3">{{ __(':findings findings, :subjects subjects: a subject found more than once (a site that is both a referring site and an AI assistant) has one card; the others are under "more about this".', ['findings' => count($findings), 'subjects' => count($cards)]) }}</p>
            @endif
        @endif
    </div>
</x-layouts.app>
