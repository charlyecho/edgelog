@props(['tone' => 'good', 'title' => null])

<div {{ $attributes->class([
    'rounded-[10px] border border-rule border-l-[3px] bg-surface px-4 py-3',
    'border-l-good' => $tone === 'good',
    'border-l-warn' => $tone === 'warn',
    'border-l-bad' => $tone === 'bad',
]) }} role="status">
    @if ($title)
        <p class="font-semibold text-ink">{{ $title }}</p>
    @endif
    <div class="text-ink-2">{{ $slot }}</div>
</div>
