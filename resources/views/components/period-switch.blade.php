@props(['current', 'today' => false])

@php
    $labels = [
        '1' => __('Yesterday'),
        '7' => __('7 days'),
        '30' => __('30 days'),
        '90' => __('90 days'),
        'all' => __('All time'),
    ];
@endphp

<nav class="inline-flex h-8 items-center rounded-lg border border-rule bg-surface p-0.5" aria-label="{{ __('Period') }}">
    @if ($today)
        <a href="{{ request()->fullUrlWithQuery(['days' => \App\Analytics\SitePeriod::TODAY]) }}" @class([
            'flex h-full items-center gap-1.5 rounded-md px-2.5 text-[13px] no-underline',
            'bg-ink font-semibold text-surface' => $current === \App\Analytics\SitePeriod::TODAY,
            'text-ink-2 hover:text-ink' => $current !== \App\Analytics\SitePeriod::TODAY,
        ]) @if ($current === \App\Analytics\SitePeriod::TODAY) aria-current="page" @endif>
            <span class="size-2 rounded-full bg-good"></span> {{ __('Today') }} · {{ __('Live') }}
        </a>
    @endif
    @foreach (\App\Analytics\SitePeriod::CHOICES as $choice)
        <a href="{{ request()->fullUrlWithQuery(['days' => $choice]) }}" @class([
            'flex h-full items-center rounded-md px-2.5 text-[13px] no-underline',
            'bg-ink font-semibold text-surface' => $choice === $current,
            'text-ink-2 hover:text-ink' => $choice !== $current,
        ]) @if ($choice === $current) aria-current="page" @endif>{{ $labels[$choice] }}</a>
    @endforeach
</nav>
