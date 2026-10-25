@php
    use App\Enums\DayStatus;

    // A day is drawn only when it is verified, whole, and recorded its visits by hour.
    $shown = collect($period->days)->map(fn ($day, $date) => $day?->status === DayStatus::Verified && ! $day->isPartial() && isset($hours[$date]));
    $most = max([1, ...collect($hours)->flatten()->all()]);
    $together = array_fill(0, 24, 0);

    foreach ($hours as $date => $byHour) {
        if ($shown[$date] ?? false) {
            foreach ($byHour as $hour => $visits) {
                $together[(int) $hour] += $visits;
            }
        }
    }

    $tallest = max(1, ...$together);
    $hatch = 'repeating-linear-gradient(135deg, var(--sunk) 0 3px, transparent 3px 6px)';
@endphp

<x-section-frame :team="$team" :site="$site" current="time" :title="__('Time')" :period="$period" :choice="$choice">
    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="text-[14px] font-bold">{{ __('Each day, hour by hour') }}</h3>
        <p class="mb-3 text-xs text-ink-3">{{ __('Visits by the hour they started. A hatched row is a day that is not shown: withheld, partial, being checked, not recorded, or counted before visits were kept by hour. Hours in :timezone.', ['timezone' => $site->timezone]) }}</p>

        <div class="overflow-x-auto">
            <div class="grid min-w-[640px] grid-cols-[72px_repeat(24,minmax(0,1fr))] gap-0.5 text-[11px]">
                <span></span>
                @foreach (range(0, 23) as $hour)
                    <span class="text-center text-ink-3">{{ $hour % 3 === 0 ? sprintf('%02d', $hour) : '' }}</span>
                @endforeach

                @foreach (array_reverse($period->days, true) as $date => $day)
                    <span class="truncate pr-2 text-ink-2">{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat('D j M') }}</span>
                    @foreach (range(0, 23) as $hour)
                        @if ($shown[$date])
                            @php($visits = $hours[$date][(string) $hour] ?? 0)
                            <span class="h-4 rounded-[2px]" title="{{ \Carbon\CarbonImmutable::parse($date)->translatedFormat('j M') }}, {{ sprintf('%02d:00', $hour) }}: {{ trans_choice(':count visit|:count visits', $visits) }}"
                                style="background: {{ $visits === 0 ? 'var(--sunk)' : 'color-mix(in srgb, var(--series-visitors) '.round(15 + 85 * $visits / $most).'%, var(--surface))' }}"></span>
                        @else
                            <span class="h-4 rounded-[2px]" style="background: {{ $hatch }}" title="{{ __('not shown') }}"></span>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="mt-3 flex items-center gap-3 text-xs text-ink-3">
            <span>{{ __('Fewer') }}</span>
            <span class="h-2.5 w-24 rounded-full" style="background: linear-gradient(90deg, color-mix(in srgb, var(--series-visitors) 15%, var(--surface)), var(--series-visitors))"></span>
            <span>{{ __('More (:count at most)', ['count' => number_format($most)]) }}</span>
            <span class="ml-3 inline-block size-3 rounded-[2px]" style="background: {{ $hatch }}"></span>
            <span>{{ __('not shown') }}</span>
        </div>
    </section>

    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <h3 class="text-[14px] font-bold">{{ __('All hours together') }}</h3>
        <p class="mb-3 text-xs text-ink-3">{{ __('Visits by the hour they started, summed over the days shown.') }}</p>
        <div class="flex h-40 items-end gap-1">
            @foreach ($together as $hour => $visits)
                <div class="flex h-full flex-1 flex-col justify-end" title="{{ sprintf('%02d:00', $hour) }}: {{ trans_choice(':count visit|:count visits', $visits) }}">
                    <span class="block rounded-t-[3px] bg-[var(--series-visitors)]" style="height: {{ $visits > 0 ? max(2, $visits / $tallest * 100) : 0 }}%"></span>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex gap-1 border-t border-rule pt-1 text-[11px] text-ink-3">
            @foreach (range(0, 23) as $hour)
                <span class="flex-1 text-center">{{ $hour % 3 === 0 ? sprintf('%02d', $hour) : '' }}</span>
            @endforeach
        </div>
    </section>
</x-section-frame>
