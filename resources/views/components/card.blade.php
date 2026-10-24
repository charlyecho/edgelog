@props(['title' => null, 'description' => null, 'flush' => false])

<section {{ $attributes->only('class')->class('grid gap-3') }}>
    @if ($title)
        <header>
            <h2 class="text-[15px] font-bold tracking-tight text-ink">{{ $title }}</h2>
            @if ($description)
                <p class="max-w-[92ch] text-ink-2">{{ $description }}</p>
            @endif
        </header>
    @endif

    <div @class(['overflow-hidden rounded-[10px] border border-rule bg-surface', 'p-4' => ! $flush])>
        {{ $slot }}
    </div>

    @isset($after)
        {{ $after }}
    @endisset
</section>
