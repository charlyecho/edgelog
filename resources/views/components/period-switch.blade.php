@props(['current'])

<nav class="inline-flex rounded-lg border border-rule bg-surface p-0.5" aria-label="{{ __('Period') }}">
    @foreach (\App\Analytics\SitePeriod::LENGTHS as $length)
        <a href="{{ request()->fullUrlWithQuery(['days' => $length]) }}" @class([
            'rounded-md px-2.5 py-1 text-[13px] font-semibold no-underline',
            'bg-ink text-surface' => $length === $current,
            'text-ink-2 hover:text-ink' => $length !== $current,
        ]) @if ($length === $current) aria-current="page" @endif>{{ trans_choice(':count day|:count days', $length) }}</a>
    @endforeach
</nav>
