@props(['title', 'unit', 'rows', 'total', 'tint' => 'var(--group-people)', 'shown' => 5, 'bare' => false])

@php($largest = max([1, ...array_column($rows, 'count')]))

<section {{ $attributes->class(['rounded-[10px] border border-rule bg-surface p-4 shadow-card' => ! $bare]) }} data-top-list>
    @if ($title !== '' || count($rows) > $shown)
        <header class="mb-2 flex items-baseline justify-between gap-3">
            <div>
                <h3 class="text-[14px] font-bold">{{ $title }}</h3>
                <p class="text-xs text-ink-3">{{ $unit }}</p>
            </div>
            @if (count($rows) > $shown)
                <button type="button" class="cursor-pointer text-[13px] text-ink-2 hover:text-ink" data-see-all data-less="{{ __('Show less') }}">{{ __('See all') }}</button>
            @endif
        </header>
    @endif

    @if ($rows === [])
        <p class="py-3 text-ink-3">{{ __('None in this period.') }}</p>
    @else
        <ol class="grid gap-1">
            @foreach ($rows as $index => $row)
                <li @class(['relative flex items-center justify-between gap-3 rounded-md px-2 py-1', 'hidden' => $index >= $shown]) @if ($index >= $shown) data-extra @endif>
                    <span class="absolute inset-y-0 left-0 rounded-md" style="width: {{ round($row['count'] / $largest * 100, 1) }}%; background: color-mix(in srgb, {{ $tint }} 17%, transparent)" aria-hidden="true"></span>
                    <span class="relative flex min-w-0 items-center gap-2">
                        @isset($row['flag'])
                            <span class="w-5 flex-none text-center" aria-hidden="true">{{ $row['flag'] }}</span>
                        @endisset
                        @isset($row['icon'])
                            <x-icon :name="$row['icon']" class="size-3.5 flex-none text-ink-2" />
                        @endisset
                        @isset($row['tile'])
                            <span class="grid size-4 flex-none place-items-center rounded-[4px] text-[10px] font-bold text-white" style="background: {{ $row['tile'][1] }}" aria-hidden="true">{{ $row['tile'][0] }}</span>
                        @endisset
                        <span class="truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        @if (! empty($row['note']))
                            <span class="flex-none text-xs text-ink-3">{{ $row['note'] }}</span>
                        @endif
                    </span>
                    <span class="relative flex flex-none items-baseline gap-2 tabular-nums">
                        <span class="font-semibold">{{ number_format($row['count']) }}</span>
                        <span class="w-9 text-right text-xs text-ink-3">{{ $total > 0 ? round($row['count'] / $total * 100).'%' : '' }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</section>
