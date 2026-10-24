@props(['label' => null])

@if ($label)
    <label class="grid gap-1.5 text-[13px] font-medium text-ink-2">
        {{ $label }}
        <select {{ $attributes->class('h-10 rounded-[9px] border border-rule bg-surface px-3 text-[15px] font-normal text-ink hover:border-ink-3') }}>{{ $slot }}</select>
    </label>
@else
    <select {{ $attributes->class('h-8 rounded-lg border border-rule bg-surface px-2 text-[13px] text-ink hover:border-ink-3') }}>{{ $slot }}</select>
@endif
