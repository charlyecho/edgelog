@props(['values', 'firstMinute', 'timezone', 'color', 'unit'])

@php
    $width = 600;
    $height = 150;
    $left = 28;
    $bottom = 22;
    $top = 8;
    $count = count($values);
    $largest = max([1, ...$values]);
    $ceiling = $largest <= 4 ? $largest : (int) (ceil($largest / 5) * 5);
    $slot = ($width - $left) / max(1, $count);
    $barWidth = max(2, $slot - 2);
    $y = fn (int $value) => $height - $bottom - $value / $ceiling * ($height - $top - $bottom);
    $minute = fn (int $index) => \Carbon\CarbonImmutable::createFromTimestamp($firstMinute + $index * 60, $timezone)->format('H:i');
@endphp

<svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full" role="img" aria-label="{{ $unit }}">
    @foreach (array_unique([0, intdiv($ceiling, 2), $ceiling]) as $tick)
        <line x1="{{ $left }}" x2="{{ $width }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--grid)" />
        <text x="{{ $left - 6 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" class="fill-ink-3 text-[10px] tabular-nums">{{ $tick }}</text>
    @endforeach

    @foreach ($values as $index => $value)
        <g>
            <title>{{ $minute($index) }}: {{ number_format($value) }} {{ $unit }}</title>
            <rect x="{{ $left + $index * $slot + 1 }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $height - $top - $bottom }}" fill="transparent" />
            @if ($value > 0)
                <rect x="{{ $left + $index * $slot + 1 }}" y="{{ $y($value) }}" width="{{ $barWidth }}" height="{{ $height - $bottom - $y($value) }}" rx="2" fill="{{ $color }}" />
            @endif
        </g>
    @endforeach

    @foreach ($values as $index => $value)
        @if ($index % 5 === 0)
            <text x="{{ $left + $index * $slot + $slot / 2 }}" y="{{ $height - 6 }}" text-anchor="middle" class="fill-ink-3 text-[10px]">{{ $minute($index) }}</text>
        @endif
    @endforeach
</svg>
