@props(['title' => null, 'unit' => null, 'columns', 'rows', 'shown' => 10, 'empty' => null])

{{--
    A table of figures: its columns are [label, 'left'|'right'], each row a
    list of cells. A cell is text, or [label, icon?, flag?, tile?, note?, mono?, tone?, href?, drawer?]:
    with href, the label is a link; with drawer, it opens in the side panel.
    Rows past $shown stay hidden behind "See all".
--}}
<section {{ $attributes->class('rounded-[10px] border border-rule bg-surface p-4 shadow-card') }} data-top-list>
    @if ($title || count($rows) > $shown)
        <header class="mb-2 flex items-baseline justify-between gap-3">
            <div>
                @if ($title)
                    <h3 class="text-[14px] font-bold">{{ $title }}</h3>
                @endif
                @if ($unit)
                    <p class="text-xs text-ink-3">{{ $unit }}</p>
                @endif
            </div>
            @if (count($rows) > $shown)
                <button type="button" class="cursor-pointer text-[13px] whitespace-nowrap text-ink-2 hover:text-ink" data-see-all data-less="{{ __('Show less') }}">{{ __('See all :count', ['count' => number_format(count($rows))]) }}</button>
            @endif
        </header>
    @endif

    @if ($rows === [])
        <p class="py-3 text-ink-3">{{ $empty ?? __('None in this period.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-[13px] tabular-nums">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        @foreach ($columns as [$label, $align])
                            <th @class(['py-2 pr-3 font-semibold last:pr-0', 'text-left' => $align === 'left', 'text-right' => $align === 'right'])>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-grid">
                    @foreach ($rows as $index => $cells)
                        <tr @class(['hidden' => $index >= $shown]) @if ($index >= $shown) data-extra @endif>
                            @foreach ($cells as $column => $cell)
                                <td @class(['py-1.5 pr-3 last:pr-0', 'text-right' => ($columns[$column][1] ?? 'left') === 'right'])>
                                    @if (is_array($cell))
                                        <span class="flex min-w-0 items-center gap-1.5">
                                            @if (! empty($cell['flag']))
                                                <span class="flex-none" aria-hidden="true">{{ $cell['flag'] }}</span>
                                            @endif
                                            @if (! empty($cell['icon']))
                                                <x-icon :name="$cell['icon']" class="size-3.5 text-ink-2" />
                                            @endif
                                            @if (! empty($cell['tile']))
                                                <span class="grid size-4 flex-none place-items-center rounded-[4px] text-[10px] font-bold text-white" style="background: {{ $cell['tile'][1] }}" aria-hidden="true">{{ $cell['tile'][0] }}</span>
                                            @endif
                                            <span @class([
                                                'truncate',
                                                'font-mono text-xs' => ! empty($cell['mono']),
                                                'text-good' => ($cell['tone'] ?? null) === 'good',
                                                'text-warn' => ($cell['tone'] ?? null) === 'warn',
                                                'font-semibold text-bad' => ($cell['tone'] ?? null) === 'bad',
                                                'text-ink-3' => ($cell['tone'] ?? null) === 'muted',
                                            ]) title="{{ $cell['label'] }}">
                                                @if (! empty($cell['href']))
                                                    <a href="{{ $cell['href'] }}" @if (! empty($cell['drawer'])) data-drawer @endif class="text-ink underline decoration-rule underline-offset-2 hover:decoration-ink">{{ $cell['label'] }}</a>
                                                @else
                                                    {{ $cell['label'] }}
                                                @endif
                                            </span>
                                            @if (! empty($cell['note']))
                                                <span class="flex-none text-xs text-ink-3">{{ $cell['note'] }}</span>
                                            @endif
                                        </span>
                                    @else
                                        {{ $cell }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{ $slot }}
</section>
