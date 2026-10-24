@props(['tabs', 'current'])

<nav class="mb-4 flex gap-1 border-b border-rule" aria-label="{{ __('Sections') }}">
    @foreach ($tabs as $key => $tab)
        <a href="{{ $tab['href'] }}" @class([
            'relative -mb-px border-b-2 px-3 pt-1 pb-2 text-[15px] no-underline',
            'border-ink font-semibold text-ink' => $key === $current,
            'border-transparent text-ink-2 hover:text-ink' => $key !== $current,
        ]) @if ($key === $current) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
