@props(['value'])

<input type="text" readonly value="{{ $value }}" onclick="this.select()" {{ $attributes->class('mt-2 h-10 w-full rounded-[9px] border border-rule bg-page px-3 font-mono text-[13px] text-ink') }}>
