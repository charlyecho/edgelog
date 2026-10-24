@php
    $ceiling = $ceiling();
    $last = $lastIndex();
    $chart = \App\View\Components\Charts\DailyChart::class;
    $W = $chart::WIDTH;
    $H = $chart::HEIGHT;
@endphp

<figure class="relative" data-daily-chart data-points='@json($points())' data-visitors="{{ __('Visitors') }}" data-pages="{{ __('Pages read') }}">
    <ul class="mb-2 flex gap-4 text-[13px] text-ink-2">
        <li class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded-full bg-[var(--series-visitors)]"></span>{{ __('Visitors') }}</li>
        <li class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded-full bg-[var(--series-pages)]"></span>{{ __('Pages read') }}</li>
    </ul>

    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full overflow-visible" role="img" aria-label="{{ __('Visitors and pages read, by day') }}">
        @foreach ([0, $ceiling / 2, $ceiling] as $tick)
            <line x1="{{ $chart::LEFT }}" x2="{{ $W - $chart::RIGHT }}" y1="{{ $y((int) $tick) }}" y2="{{ $y((int) $tick) }}" stroke="var(--grid)" stroke-width="1" />
            <text x="{{ $chart::LEFT - 8 }}" y="{{ $y((int) $tick) + 4 }}" text-anchor="end" class="fill-ink-3 text-[11px] tabular-nums">{{ number_format($tick) }}</text>
        @endforeach

        @foreach ($series as $index => $point)
            @if ($point['status'] === 'withheld')
                <rect x="{{ $x($index) - 3 }}" y="{{ $chart::TOP }}" width="6" height="{{ $H - $chart::TOP - $chart::BOTTOM }}" fill="var(--sunk)" />
                <text x="{{ $x($index) }}" y="{{ $H - $chart::BOTTOM - 4 }}" text-anchor="middle" class="fill-bad text-[10px] font-bold">!</text>
            @endif
        @endforeach

        <path d="{{ $path('pages') }}" fill="none" stroke="var(--series-pages)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <path d="{{ $path('visitors') }}" fill="none" stroke="var(--series-visitors)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

        @foreach (['pages', 'visitors'] as $key)
            @foreach ($isolated($key) as $index)
                <circle cx="{{ $x($index) }}" cy="{{ $y($series[$index][$key]) }}" r="4" fill="var(--series-{{ $key }})" stroke="var(--surface)" stroke-width="2" />
            @endforeach
        @endforeach

        @if ($last !== null)
            <text x="{{ $x($last) + 8 }}" y="{{ $y($series[$last]['pages']) + 4 }}" class="fill-ink-2 text-[12px] font-semibold">{{ __('Pages read') }}</text>
            <text x="{{ $x($last) + 8 }}" y="{{ $y($series[$last]['visitors']) + 4 + (abs($y($series[$last]['visitors']) - $y($series[$last]['pages'])) < 14 ? 14 : 0) }}" class="fill-ink-2 text-[12px] font-semibold">{{ __('Visitors') }}</text>
        @endif

        @foreach ($ticks() as $index => $label)
            <text x="{{ $x($index) }}" y="{{ $H - 8 }}" text-anchor="middle" class="fill-ink-3 text-[11px]">{{ $label }}</text>
        @endforeach

        <line data-crosshair x1="0" x2="0" y1="{{ $chart::TOP }}" y2="{{ $H - $chart::BOTTOM }}" stroke="var(--ink-3)" stroke-width="1" stroke-dasharray="3 3" visibility="hidden" />
        <rect data-hover x="{{ $chart::LEFT - 10 }}" y="0" width="{{ $W - $chart::LEFT - $chart::RIGHT + 20 }}" height="{{ $H }}" fill="transparent" />
    </svg>

    <div data-tooltip class="pointer-events-none absolute top-8 z-10 hidden min-w-40 rounded-lg border border-rule bg-surface px-3 py-2 text-[13px] shadow-card" role="status"></div>

    <details class="mt-3 text-[13px]">
        <summary class="cursor-pointer text-ink-2">{{ __('Show as a table') }}</summary>
        <table class="mt-2 w-full max-w-md text-left tabular-nums">
            <thead class="text-ink-3"><tr><th class="py-1 font-semibold">{{ __('Day') }}</th><th class="py-1 text-right font-semibold">{{ __('Visitors') }}</th><th class="py-1 text-right font-semibold">{{ __('Pages read') }}</th></tr></thead>
            <tbody class="divide-y divide-grid">
                @foreach ($points() as $point)
                    <tr>
                        <td class="py-1">{{ $point['label'] }}</td>
                        @if ($point['visitors'] === null)
                            <td class="py-1 text-right text-ink-3" colspan="2">{{ $point['note'] }}</td>
                        @else
                            <td class="py-1 text-right">{{ number_format($point['visitors']) }}</td>
                            <td class="py-1 text-right">{{ number_format($point['pages']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
