@php
    $chart = \App\View\Components\Charts\ServerChart::class;
    $W = $chart::WIDTH;
    $H = $chart::HEIGHT;
@endphp

<figure class="relative rounded-[10px] border border-rule bg-surface p-4 shadow-card" data-series-chart data-lines='@json($legend())' data-points='@json($hoverPoints())'>
    <figcaption class="mb-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
        <h3 class="text-[15px] font-bold">{{ $title }}</h3>
        @if (count($lines) > 1 || $guide !== null)
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
                @foreach ($lines as $line)
                    <li class="flex items-center gap-1.5">
                        <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true"><line x1="1" x2="15" y1="4" y2="4" stroke="{{ $line['color'] }}" stroke-width="2" stroke-linecap="round" @if ($line['dashed'] ?? false) stroke-dasharray="3 3" @endif /></svg>
                        {{ $line['label'] }}
                    </li>
                @endforeach
                @if ($guide !== null)
                    <li class="flex items-center gap-1.5">
                        <svg class="h-2 w-4" viewBox="0 0 16 8" aria-hidden="true"><line x1="1" x2="15" y1="4" y2="4" stroke="var(--ink-3)" stroke-width="1" stroke-dasharray="2 2" /></svg>
                        {{ $guide['label'] }}
                    </li>
                @endif
            </ul>
        @endif
    </figcaption>

    @if (! $hasValues())
        <p class="flex h-32 items-center justify-center rounded-lg border border-dashed border-rule text-[13px] text-ink-3">{{ __('No figure in this range.') }}</p>
    @else
        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full overflow-visible" role="img" aria-label="{{ $title }}">
            @foreach ($axis() as $tick)
                <line x1="{{ $chart::LEFT }}" x2="{{ $W - $chart::RIGHT }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--grid)" stroke-width="1" />
                <text x="{{ $chart::LEFT - 8 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" class="fill-ink-3 text-[11px] tabular-nums">{{ $format($tick) }}</text>
            @endforeach

            @if ($guide !== null)
                <line x1="{{ $chart::LEFT }}" x2="{{ $W - $chart::RIGHT }}" y1="{{ $y($guide['value']) }}" y2="{{ $y($guide['value']) }}" stroke="var(--ink-3)" stroke-width="1" stroke-dasharray="4 4" />
            @endif

            @foreach ($lines as $line)
                @if ($line['area'] ?? false)
                    <path d="{{ $area($line['key']) }}" fill="color-mix(in srgb, {{ $line['color'] }} 12%, transparent)" />
                @endif
            @endforeach

            @foreach (array_reverse($lines) as $line)
                <path d="{{ $path($line['key']) }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="{{ ($line['dashed'] ?? false) ? 1.5 : 2 }}" stroke-linejoin="round" stroke-linecap="round" @if ($line['dashed'] ?? false) stroke-dasharray="4 4" @endif />
                @foreach ($isolated($line['key']) as $index)
                    <circle cx="{{ $x($index) }}" cy="{{ $y($points[$index][$line['key']]) }}" r="4" fill="{{ $line['color'] }}" stroke="var(--surface)" stroke-width="2" />
                @endforeach
            @endforeach

            @foreach ($ticks() as $index => $label)
                <text x="{{ $x($index) }}" y="{{ $H - 8 }}" text-anchor="middle" class="fill-ink-3 text-[11px]">{{ $label }}</text>
            @endforeach

            <line data-crosshair x1="0" x2="0" y1="{{ $chart::TOP }}" y2="{{ $H - $chart::BOTTOM }}" stroke="var(--ink-3)" stroke-width="1" stroke-dasharray="3 3" visibility="hidden" />
            <rect data-hover x="{{ $chart::LEFT - 10 }}" y="0" width="{{ $W - $chart::LEFT - $chart::RIGHT + 20 }}" height="{{ $H }}" fill="transparent" />
        </svg>

        <div data-tooltip class="pointer-events-none absolute top-12 z-10 hidden min-w-44 rounded-lg border border-rule bg-surface px-3 py-2 text-[13px] shadow-card" role="status"></div>

        <details class="mt-2 text-[13px]" data-table data-time="{{ __('Time') }}">
            <summary class="cursor-pointer text-ink-2">{{ __('Show as a table') }}</summary>
            <div class="mt-2 max-h-72 overflow-y-auto"></div>
        </details>
    @endif
</figure>
