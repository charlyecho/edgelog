@props(['values', 'color'])

@php
    $width = 160;
    $height = 36;
    $known = array_filter($values, fn ($value) => $value !== null);
    $top = max([1, ...$known]);
    $count = max(1, count($values) - 1);
    $point = fn (int $index, int $value) => round($index * $width / $count, 1).' '.round($height - 3 - $value / $top * ($height - 8), 1);
    $line = '';
    $drawing = false;
    $lastIndex = null;

    foreach ($values as $index => $value) {
        if ($value === null) {
            $drawing = false;

            continue;
        }

        $line .= ($drawing ? 'L' : 'M').$point($index, $value).' ';
        $drawing = true;
        $lastIndex = $index;
    }
@endphp

@if (count($known) > 1)
    <svg viewBox="-4 0 {{ $width + 8 }} {{ $height }}" class="mt-2 h-9 w-full max-w-44 overflow-visible" aria-hidden="true">
        <line x1="0" x2="{{ $width }}" y1="{{ $height - 3 }}" y2="{{ $height - 3 }}" stroke="var(--grid)" />
        <path d="{{ trim($line) }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        @if ($lastIndex !== null)
            @php([$x, $y] = explode(' ', $point($lastIndex, $values[$lastIndex])))
            <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="{{ $color }}" stroke="var(--surface)" stroke-width="1.5" />
        @endif
    </svg>
@endif
