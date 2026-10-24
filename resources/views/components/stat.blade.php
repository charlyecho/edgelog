@props(['label', 'value', 'note' => null])

<div {{ $attributes->class('bg-surface px-4 py-3') }}>
    <p class="text-[13px] font-semibold text-ink-2">{{ $label }}</p>
    <p class="mt-0.5 text-[28px] leading-tight font-bold tracking-tight tabular-nums">{{ $value }}</p>
    @if ($note)
        <p class="mt-0.5 text-xs text-ink-3">{{ $note }}</p>
    @endif
</div>
