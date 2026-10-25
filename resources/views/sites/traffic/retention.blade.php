@php
    $share = fn (array $part) => $part['of'] > 0 ? $part['back'] / $part['of'] : null;
    $words = fn (?float $ratio) => match (true) {
        $ratio === null => '—',
        $ratio > 0 && $ratio < 0.01 => '<1%',
        default => round($ratio * 100).'%',
    };
    $next = $retention->backIn(1);
    $fourth = $retention->backIn(min(4, $retention->follow()));
    $highest = max(0.0001, ...collect($retention->cohorts)->flatMap(fn ($cohort) => collect($cohort['back'])->filter(fn ($back) => $back !== null)->map(fn ($back) => $cohort['new'] > 0 ? $back / $cohort['new'] : 0))->all() ?: [0.0001]);
    $period = $grain === 'week' ? __('week') : __('day');
    $curve = array_map(fn (int $later) => $share($retention->backIn($later)), range(1, $retention->follow()));
    $curveTop = max(0.0001, ...array_filter($curve, fn ($ratio) => $ratio !== null) ?: [0.0001]);
@endphp

<x-section-frame :team="$team" :site="$site" current="retention" :title="__('Retention')">
    <p class="-mt-2 max-w-[92ch] text-[13px] text-ink-2">
        {{ $grain === 'week' ? __('The last 12 weeks of first visits') : __('The last 30 days of first visits') }} · {{ __('the period bar does not apply here') }}.
        {{ __('The same visitor: the same keyed hash of address and browser, kept :days days. New: first seen since what is kept begins.', ['days' => config('analytics.visitors_kept_days')]) }}
        @if ($retention->keptFrom)
            {{ __('What is kept begins :day: for now, "new" means first seen since then.', ['day' => $retention->keptFrom->translatedFormat('D j M Y')]) }}
        @endif
    </p>

    <div class="flex flex-wrap items-center gap-3">
        <x-segmented name="by" :current="$grain" default="week" :options="['week' => __('Weeks'), 'day' => __('Days')]" />
        <x-segmented name="back" :current="$readers ? 'read' : 'came'" default="came" :options="['came' => __('Came back'), 'read' => __('Read 2+ pages')]" />
    </div>

    @if ($retention->keptFrom === null)
        <x-card :title="__('No visitors kept yet')" :description="__('Retention reads the visitors kept from the days counted since visits were recorded. It fills in as days are counted.')" />
    @else
        <div class="overflow-hidden rounded-[10px] border border-rule bg-rule shadow-card">
            <div class="grid grid-cols-1 gap-px sm:grid-cols-3">
                @foreach ([
                    [__('New visitors'), number_format($retention->newVisitors()), $grain === 'week' ? __('first seen in the 12 weeks counted') : __('first seen in the 30 days counted')],
                    [$grain === 'week' ? __('Back the next week') : __('Back the next day'), $words($share($next)), __(':back of :of came back', ['back' => number_format($next['back']), 'of' => number_format($next['of'])])],
                    [$grain === 'week' ? __('Back week +:n', ['n' => min(4, $retention->follow())]) : __('Back day +:n', ['n' => min(4, $retention->follow())]), $words($share($fourth)), __(':back of :of came back', ['back' => number_format($fourth['back']), 'of' => number_format($fourth['of'])])],
                ] as [$label, $value, $note])
                    <div class="bg-surface p-4">
                        <p class="text-[13px] font-semibold text-ink-2">{{ $label }}</p>
                        <p class="text-2xl font-bold tabular-nums">{{ $value }}</p>
                        <p class="text-xs text-ink-3">{{ $note }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <h3 class="text-[14px] font-bold">{{ __('New visitors, and the share back each :period after', ['period' => $period]) }}</h3>
            <p class="mb-3 text-xs text-ink-3">{{ __('Each row is the visitors first seen that :period; each column a later :period.', ['period' => $period]) }}</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-separate border-spacing-0.5 text-[12px] tabular-nums">
                    <thead class="text-ink-2">
                        <tr>
                            <th class="py-1 text-left font-semibold">{{ $grain === 'week' ? __('First week') : __('First day') }}</th>
                            <th class="py-1 pr-2 text-right font-semibold">{{ __('New visitors') }}</th>
                            @foreach (range(1, $retention->follow()) as $later)
                                <th class="py-1 text-center font-semibold">+{{ $later }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($retention->cohorts as $cohort)
                            <tr>
                                <td class="py-1 pr-2 whitespace-nowrap text-ink-2">
                                    {{ $grain === 'week' ? $cohort['start']->translatedFormat('j M').' – '.$cohort['end']->translatedFormat('j M') : $cohort['start']->translatedFormat('D j M') }}
                                </td>
                                <td class="py-1 pr-2 text-right font-semibold">{{ number_format($cohort['new']) }}</td>
                                @foreach ($cohort['back'] as $index => $back)
                                    @php($state = $cohort['state'][$index])
                                    @if ($state === 'counted' && $cohort['new'] > 0)
                                        @php($ratio = $back / $cohort['new'])
                                        <td class="h-9 rounded-[3px] text-center leading-tight" title="{{ __(':back of :of came back', ['back' => number_format($back), 'of' => number_format($cohort['new'])]) }}"
                                            style="background: color-mix(in srgb, var(--series-visitors) {{ round(8 + 92 * $ratio / $highest) }}%, var(--surface)); color: {{ $ratio / $highest > 0.5 ? 'white' : 'var(--ink)' }}">
                                            <span class="block font-semibold">{{ $words($ratio) }}</span><span class="block text-[10.5px] opacity-80">{{ number_format($back) }}</span>
                                        </td>
                                    @elseif ($state === 'missing')
                                        <td class="h-9 rounded-[3px]" style="background: repeating-linear-gradient(135deg, var(--sunk) 0 3px, transparent 3px 6px)" title="{{ __('not recorded') }}"></td>
                                    @else
                                        <td class="h-9" title="{{ __('not over yet') }}"></td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-3">
                <span class="flex items-center gap-1.5"><span class="inline-block size-3 rounded-[2px] bg-[var(--series-visitors)]"></span>{{ __(':share (the highest here)', ['share' => $words($highest === 0.0001 ? null : $highest)]) }}</span>
                <span class="flex items-center gap-1.5"><span class="inline-block size-3 rounded-[2px]" style="background: repeating-linear-gradient(135deg, var(--sunk) 0 3px, transparent 3px 6px)"></span>{{ __('not recorded') }}</span>
                <span>{{ __('blank: not over yet') }}</span>
            </p>
        </section>

        <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
            <h3 class="text-[14px] font-bold">{{ __('Retention curve') }}</h3>
            <p class="mb-3 text-xs text-ink-3">{{ __('The share of new visitors back in each :period after their first, over every cohort counted there.', ['period' => $period]) }}</p>
            <div class="flex h-36 items-end gap-2">
                @foreach ($curve as $index => $ratio)
                    <div class="flex h-full flex-1 flex-col justify-end text-center" title="+{{ $index + 1 }}: {{ $words($ratio) }}">
                        <span class="mb-0.5 text-[11px] font-semibold tabular-nums">{{ $words($ratio) }}</span>
                        <span class="block rounded-t-[3px] bg-[var(--series-visitors)]" style="height: {{ $ratio ? max(2, $ratio / $curveTop * 85) : 0 }}%"></span>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex gap-2 border-t border-rule pt-1 text-[11px] text-ink-3">
                @foreach (range(1, $retention->follow()) as $later)
                    <span class="flex-1 text-center">+{{ $later }}</span>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-ink-3">{{ __('One address can be several people (an office), and one person on two devices is two visitors.') }}</p>
        </section>
    @endif
</x-section-frame>
