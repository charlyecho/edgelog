@props(['name', 'current', 'options', 'default' => null])

{{-- A row of choices kept in the address: each option is [value => label]. --}}
<nav {{ $attributes->class('inline-flex h-8 items-center rounded-lg border border-rule bg-surface p-0.5 text-[13px]') }}>
    @foreach ($options as $value => $label)
        <a href="{{ request()->fullUrlWithQuery([$name => $value === $default ? null : $value]) }}" @class([
            'flex h-full items-center gap-1.5 rounded-md px-2.5 no-underline',
            'bg-ink font-semibold text-surface' => (string) $current === (string) $value,
            'text-ink-2 hover:text-ink' => (string) $current !== (string) $value,
        ]) @if ((string) $current === (string) $value) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</nav>
