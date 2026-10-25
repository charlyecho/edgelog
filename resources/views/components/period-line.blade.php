@props(['period'])

@php
    $verified = $period->verified()->count();
    $whole = $period->verified()->filter(fn ($day) => ! $day->isPartial())->count();
@endphp

<p class="mb-3 text-[13px] text-ink-2">
    @if ($period->length() === 1)
        {{ __('Yesterday, :day', ['day' => $period->first->translatedFormat('l j M')]) }}
    @else
        {{ __('Last :count whole days, :from to :to', ['count' => $period->length(), 'from' => $period->first->translatedFormat('j M'), 'to' => $period->last->translatedFormat('j M')]) }}
    @endif
    ·
    @if ($whole === $period->length())
        {{ $period->length() === 1 ? __('the day is whole and verified') : __('every day whole and verified') }}
    @else
        {{ __(':verified of :days days verified', ['verified' => $verified, 'days' => $period->length()]) }}
        @if ($period->withheld() !== [])
            · <span class="text-bad">{{ trans_choice(':count withheld|:count withheld', count($period->withheld())) }}</span>
        @endif
        @if ($period->pending() !== [])
            · {{ trans_choice(':count being checked|:count being checked', count($period->pending())) }}
        @endif
        @if ($period->missing() !== [])
            · {{ trans_choice(':count not recorded|:count not recorded', count($period->missing())) }}
        @endif
    @endif
    · {{ __('All pages') }}
</p>
