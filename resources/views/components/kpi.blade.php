@props(['label', 'value', 'previous' => null, 'comparable' => false, 'format' => 'number', 'currency' => null, 'series' => [], 'color' => 'var(--series-visitors)', 'note' => null, 'info' => null, 'extra' => null, 'href' => null])

@php
    $show = fn ($number) => match (true) {
        $number === null => '–',
        $format === 'ratio' => number_format($number, 1),
        $format === 'percent' => \Illuminate\Support\Number::format($number, maxPrecision: 1).'%',
        $format === 'load' => number_format($number, 2),
        $format === 'bytes' => __(':size/s', ['size' => \Illuminate\Support\Number::fileSize($number, maxPrecision: 1)]),
        $format === 'money' => \App\Integrations\Money::format((int) $number, $currency),
        default => number_format($number),
    };
    $change = null;

    if ($comparable && $value !== null && $previous !== null && $previous > 0) {
        $change = (int) round(($value - $previous) / $previous * 100);
    }
@endphp

<div {{ $attributes->class('min-w-0 bg-surface px-4 pt-3 pb-3') }}>
    <p class="flex items-center gap-1.5 text-[13px] font-semibold text-ink-2">
        @if ($href)
            <a href="{{ $href }}" class="hover:text-ink hover:underline">{{ $label }}</a>
        @else
            {{ $label }}
        @endif
        @if ($info)
            <x-info :text="$info" />
        @endif
    </p>
    <p class="mt-0.5 text-[30px] leading-tight font-bold tracking-tight tabular-nums">{{ $show($value) }}</p>

    <p class="mt-0.5 text-xs text-ink-3 tabular-nums">
        @if ($change !== null && $change !== 0)
            <span class="text-ink-2">{{ $change > 0 ? '▲' : '▼' }} {{ abs($change) }}%</span> {{ __('from :value', ['value' => $show($previous)]) }}
        @elseif ($change === 0)
            {{ __('about the same') }} {{ __('from :value', ['value' => $show($previous)]) }}
        @elseif ($note)
            {{ $note }}
        @endif
    </p>

    @if ($extra)
        <p class="mt-0.5 text-xs text-ink-3">{{ $extra }}</p>
    @endif

    <x-sparkline :values="$series" :color="$color" />
</div>
