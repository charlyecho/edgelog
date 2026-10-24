@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'relative flex h-[34px] items-center gap-2.5 rounded-lg px-2.5 text-sm no-underline',
    'border border-rule bg-surface font-semibold text-ink shadow-card before:absolute before:top-2 before:bottom-2 before:-left-px before:w-[3px] before:rounded-r-[3px] before:bg-ink' => $active,
    'font-medium text-ink-2 hover:bg-sunk hover:text-ink' => ! $active,
]) }}>
    <x-icon :name="$icon" />
    {{ $slot }}
</a>
