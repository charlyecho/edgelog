@props(['title', 'unit', 'rows', 'total', 'labels' => []])

@php($largest = max([1, ...array_values($rows)]))

<section {{ $attributes->class('rounded-[10px] border border-rule bg-surface p-4') }}>
    <header class="mb-2">
        <h3 class="text-[14px] font-bold">{{ $title }}</h3>
        <p class="text-xs text-ink-3">{{ $unit }}</p>
    </header>

    @if ($rows === [])
        <p class="py-3 text-ink-3">{{ __('None in this period.') }}</p>
    @else
        <ol class="grid gap-1">
            @foreach ($rows as $name => $count)
                <li class="relative flex items-center justify-between gap-3 rounded-md px-2 py-1">
                    <span class="absolute inset-y-0 left-0 rounded-md bg-[color-mix(in_srgb,#2a78d6_16%,transparent)]" style="width: {{ round($count / $largest * 100, 1) }}%" aria-hidden="true"></span>
                    <span class="relative min-w-0 truncate" title="{{ $labels[$name] ?? $name }}">{{ $labels[$name] ?? ($name === \App\Enums\CountKind::OTHERS ? __('(all others)') : $name) }}</span>
                    <span class="relative flex flex-none items-baseline gap-2 tabular-nums">
                        <span class="font-semibold">{{ number_format($count) }}</span>
                        <span class="w-9 text-right text-xs text-ink-3">{{ $total > 0 ? round($count / $total * 100).'%' : '' }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</section>
