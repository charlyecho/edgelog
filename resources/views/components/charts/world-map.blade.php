{{--
    A world map: each country shaded by its count (at most five shades, light
    to dark), and, live, a pulsing bubble on each country with someone active
    in the last 30 minutes, its area following how many. Hover or focus a
    country for its figures; the table beside the map holds the same.

    countries: [code => ['count' => int, 'active' => int]]; codes that are not
    two letters (Unknown) are left off the map.
--}}
@props(['countries', 'label', 'legend', 'count', 'active' => null, 'live' => false])

@php
    use App\Analytics\Geo\WorldMap;

    $map = WorldMap::shapes();
    $countries = collect($countries)->filter(fn ($figures, $code) => preg_match('/^[A-Z]{2}$/', (string) $code) && ($figures['count'] > 0 || ($figures['active'] ?? 0) > 0));
    $ranges = WorldMap::ranges((int) $countries->max('count'));
    $name = fn (string $code) => \Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code;
    $detail = fn (array $figures) => trim(trans_choice($count, $figures['count']).($live ? ' · '.trans_choice($active, $figures['active'] ?? 0) : ''));
    $quiet = collect($map['paths'])->except($countries->keys());
    $bubbles = $live ? $countries->filter(fn ($figures, $code) => ($figures['active'] ?? 0) > 0 && isset($map['points'][$code]))->sortBy('active') : collect();
    $largestActive = max(1, (int) $bubbles->max('active'));
    $range = fn (array $bounds) => $bounds[0] === $bounds[1] ? number_format($bounds[0]) : number_format($bounds[0]).'–'.number_format($bounds[1]);
@endphp

<figure {{ $attributes->class(['relative']) }} data-world-map>
    <svg viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" class="block h-auto w-full overflow-visible" role="group" aria-label="{{ $label }}">
        <g class="map-land">
            @foreach ($quiet as $code => $path)
                <path d="{{ $path }}" class="map-c"></path>
            @endforeach
            @foreach ($countries->sortBy('count') as $code => $figures)
                @php($shade = WorldMap::shade($figures['count'], $ranges))
                @if (isset($map['paths'][$code]))
                    <path d="{{ $map['paths'][$code] }}" class="map-c s{{ $shade }}" tabindex="0" role="img" aria-label="{{ $name($code) }}: {{ $detail($figures) }}" data-name="{{ $name($code) }}" data-detail="{{ $detail($figures) }}"></path>
                @elseif (isset($map['points'][$code]))
                    <circle cx="{{ $map['points'][$code][0] }}" cy="{{ $map['points'][$code][1] }}" r="2.6" class="map-c s{{ $shade }}" tabindex="0" role="img" aria-label="{{ $name($code) }}: {{ $detail($figures) }}" data-name="{{ $name($code) }}" data-detail="{{ $detail($figures) }}"></circle>
                @endif
            @endforeach
        </g>
        @if ($bubbles->isNotEmpty())
            <g class="map-bubbles" aria-hidden="true">
                @foreach ($bubbles as $code => $figures)
                    @php([$x, $y] = $map['points'][$code])
                    @php($radius = round(3 + 11 * sqrt($figures['active'] / $largestActive), 1))
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $radius }}" class="map-ring"></circle>
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $radius }}" class="map-ring map-ring-late"></circle>
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $radius }}" class="map-bubble"></circle>
                @endforeach
            </g>
        @endif
    </svg>

    <div class="pointer-events-none absolute z-10 hidden max-w-60 rounded-lg border border-rule bg-surface px-2.5 py-1.5 text-[12.5px] shadow-card" data-tooltip>
        <p class="font-semibold" data-tooltip-name></p>
        <p class="text-ink-2" data-tooltip-detail></p>
    </div>

    <figcaption class="mt-2 flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[11.5px] text-ink-2 tabular-nums" aria-hidden="true">
        <span class="text-ink-3">{{ $legend }}</span>
        @forelse ($ranges as $index => $bounds)
            <span class="inline-flex items-center gap-1.5"><i class="map-swatch s{{ WorldMap::shadeOfRange($index, count($ranges)) }}"></i>{{ $range($bounds) }}</span>
        @empty
            <span class="inline-flex items-center gap-1.5"><i class="map-swatch"></i>{{ __('none yet') }}</span>
        @endforelse
        @if ($live)
            <span class="inline-flex items-center gap-1.5"><i class="map-swatch map-swatch-live"></i>{{ __('active in the last 30 minutes (size: how many)') }}</span>
        @endif
    </figcaption>
</figure>
