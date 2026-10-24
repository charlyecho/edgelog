@props(['label', 'seed' => null, 'size' => 'size-6'])

@php($hue = crc32((string) ($seed ?? $label)) % 360)

<span {{ $attributes->class([$size, 'grid flex-none place-items-center rounded-[26%] font-bold leading-none text-white shadow-[inset_0_0_0_1px_rgb(0_0_0/0.12)]']) }} style="background: hsl({{ $hue }} 55% 42%)" aria-hidden="true">
    {{ Str::upper(Str::substr($label, 0, 1)) }}
</span>
