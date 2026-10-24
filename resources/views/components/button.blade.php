@props(['variant' => 'primary', 'size' => 'md', 'href' => null])

@php
    $classes = Arr::toCssClasses([
        'inline-flex items-center justify-center gap-1.5 whitespace-nowrap border font-semibold cursor-pointer disabled:cursor-default disabled:opacity-55',
        'h-10 rounded-[9px] px-4 text-sm' => $size === 'md',
        'h-8 rounded-lg px-3 text-[13px]' => $size === 'sm',
        'border-ink bg-ink text-surface hover:opacity-90' => $variant === 'primary',
        'border-rule bg-surface text-ink hover:border-ink-3' => $variant === 'secondary',
        'border-[color-mix(in_srgb,var(--bad)_40%,var(--rule))] bg-surface text-bad hover:border-bad' => $variant === 'danger',
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }}>{{ $slot }}</button>
@endif
