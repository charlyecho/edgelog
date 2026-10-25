@props(['flow'])

@php
    $colors = [
        'source' => 'var(--series-visitors)',
        'page' => 'var(--series-pages)',
        'other' => 'var(--gap)',
        'more' => 'var(--ink-2)',
        'left' => 'var(--ink-3)',
    ];
    $names = collect($flow->columns)->flatMap(fn ($nodes, $column) => collect($nodes)->mapWithKeys(fn ($node) => ["{$column}\0{$node['key']}" => $node['label']]));
    $width = \App\Analytics\VisitorFlow::WIDTH;
    $height = \App\Analytics\VisitorFlow::HEIGHT;
@endphp

<figure class="overflow-x-auto">
    <div style="width: {{ $width + 2 }}px">
        <div class="relative mb-1 h-5 text-xs font-semibold text-ink-2">
            @foreach ($flow->headings() as $column => $heading)
                <span class="absolute top-0" style="left: {{ $flow->columnX()[$column] }}px">{{ $heading }}</span>
            @endforeach
        </div>
        <svg viewBox="-1 -1 {{ $width + 2 }} {{ $height + 2 }}" width="{{ $width + 2 }}" height="{{ $height + 2 }}" class="block max-w-none" role="img" aria-label="{{ __('Visitor flow') }}">
            <g>
                @foreach ($flow->links as $link)
                    <path d="{{ $link['path'] }}" fill="var(--gap)" fill-opacity="0.45" class="hover:fill-opacity-80">
                        <title>{{ $names["{$link['from'][0]}\0{$link['from'][1]}"] }} → {{ $names["{$link['to'][0]}\0{$link['to'][1]}"] }}: {{ trans_choice(':count visit|:count visits', $link['count']) }}</title>
                    </path>
                @endforeach
            </g>
            <g>
                @foreach ($flow->columns as $column => $nodes)
                    @foreach ($nodes as $node)
                        <g>
                            <title>{{ $node['label'] }}: {{ trans_choice(':count visit|:count visits', $node['count']) }}</title>
                            <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="8" height="{{ $node['h'] }}" rx="1.5" fill="{{ $colors[$node['kind']] }}" />
                            @if ($node['h'] >= 2)
                                <text x="{{ $node['x'] + 12 }}" y="{{ $node['y'] + $node['h'] / 2 }}" dominant-baseline="central" class="fill-ink text-[11px]" style="paint-order: stroke; stroke: var(--surface); stroke-width: 3px; stroke-linejoin: round">{{ Str::limit($node['label'], 24) }} <tspan class="fill-ink-3">{{ number_format($node['count']) }}</tspan></text>
                            @endif
                        </g>
                    @endforeach
                @endforeach
            </g>
        </svg>
    </div>

    <figcaption class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
        @foreach ([
            'source' => $flow->start === 'via' ? __('Who sent them') : __('Where visits came from'),
            'page' => __('A page'),
            'other' => __('Folded: the smaller values'),
            'more' => __('Went on to more pages'),
            'left' => __('Left the site'),
        ] as $kind => $legend)
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[3px]" style="background: {{ $colors[$kind] }}"></span>{{ $legend }}</span>
        @endforeach
    </figcaption>
</figure>
