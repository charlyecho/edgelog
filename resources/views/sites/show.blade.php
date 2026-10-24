@php
    use App\Enums\CountKind;
    use App\Enums\SourceType;
    use App\Enums\TrafficGroup;

    $people = $period->group(TrafficGroup::People);
    $engines = $period->group(TrafficGroup::AnswerEngines);
    $verified = $period->verified()->count();
    $sourceLabels = collect(SourceType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all();
    $tierLabels = collect(\App\Enums\EngineTier::cases())->mapWithKeys(fn ($tier) => [$tier->value => $tier->label()])->all();
@endphp

<x-layouts.app :title="__('Overview')">
    <x-slot:trail>
        <x-crumb :href="route('teams.show', $team)">{{ $team->name }}</x-crumb>
        <x-crumb :href="route('teams.sites.show', [$team, $site])">{{ $site->name }}</x-crumb>
    </x-slot:trail>

    <x-slot:actions>
        <x-period-switch :current="$period->length()" />
    </x-slot:actions>

    <p class="mb-5 text-[13px] text-ink-2">
        {{ __('Last :count whole days, :from to :to', ['count' => $period->length(), 'from' => $period->first->translatedFormat('j M'), 'to' => $period->last->translatedFormat('j M')]) }}
        · {{ trans_choice(':count day verified|:count days verified', $verified) }}
        @if ($period->withheld() !== [])
            · <span class="text-bad">{{ trans_choice(':count day withheld|:count days withheld', count($period->withheld())) }}</span>
        @endif
        @if ($period->pending() !== [])
            · {{ trans_choice(':count day being checked|:count days being checked', count($period->pending())) }}
        @endif
        @if ($period->missing() !== [])
            · {{ trans_choice(':count day not recorded|:count days not recorded', count($period->missing())) }}
        @endif
        · {{ $site->timezone }}
    </p>

    @if (! $period->hasFigures())
        <x-card :title="__('No figures yet')" :description="__('A day is counted once it is over where the site is, then counted a second time by the audit. Its figures show here only when both counts agree.')">
            <p class="text-ink-2">
                @can('update', $site)
                    {{ __('Check that the site sends its log in') }} <a href="{{ route('teams.sites.edit', [$team, $site]) }}" class="font-semibold text-ink underline">{{ __('Site settings') }}</a>.
                @else
                    {{ __('The first figures appear the day after the site sends its first log lines.') }}
                @endif
            </p>
        </x-card>
    @else
        <div class="grid gap-6">
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-[10px] border border-rule bg-rule lg:grid-cols-6">
                <x-stat :label="__('Visitors')" :value="number_format($people->readers)" :note="__('people who read a page')" />
                <x-stat :label="__('Pages read')" :value="number_format($people->views)" :note="__(':ratio per visitor', ['ratio' => $people->readers > 0 ? number_format($people->views / $people->readers, 1) : '-'])" />
                <x-stat :label="__('Visitors from AI')" :value="number_format($period->citedVisits())" :note="__('followed an AI assistant\'s link')" />
                <x-stat :label="__('AI assistants')" :value="number_format($engines->requests)" :note="__('requests, to train or to answer')" />
                <x-stat :label="__('Search engines')" :value="number_format($period->group(TrafficGroup::SearchEngines)->requests)" :note="__('requests')" />
                <x-stat :label="__('Bots and scanners')" :value="number_format($period->group(TrafficGroup::Bots)->requests)" :note="__('requests')" />
            </div>

            <x-card :title="__('Visitors and pages read, by day')" :description="__('A line breaks at a day with no figures: withheld because its two counts disagree, being checked, or not recorded. A missing day is never drawn as zero.')">
                <x-charts.daily-chart :series="$period->series()" />
            </x-card>

            <x-card :title="__('Every request, in five groups')" :description="__('Each request lands in exactly one group, so the five add up to the log.')">
                <x-charts.groups-bar :period="$period" />
            </x-card>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-breakdown :title="__('Top pages')" :unit="__('pages read by people')" :rows="$period->top(CountKind::PeoplePage)" :total="$people->views" />
                <x-breakdown :title="__('Sources')" :unit="__('visitors, by where they came from')" :rows="$period->top(CountKind::PeopleSource)" :total="$people->readers" :labels="$sourceLabels" />
                <x-breakdown :title="__('Referrers')" :unit="__('visitors')" :rows="$period->top(CountKind::PeopleReferrer)" :total="$people->readers" />
                <x-breakdown :title="__('Landing pages')" :unit="__('visitors, by their first page')" :rows="$period->top(CountKind::PeopleLanding)" :total="$people->readers" />
                <x-breakdown :title="__('Visitors from AI assistants')" :unit="__('visitors who followed a citation')" :rows="$period->top(CountKind::CitedBy)" :total="$period->citedVisits()" />
                <x-breakdown :title="__('AI assistants')" :unit="__('requests, by assistant')" :rows="$period->top(CountKind::EngineName)" :total="$engines->requests" />
                <x-breakdown :title="__('AI assistants, by why they came')" :unit="__('requests')" :rows="$period->top(CountKind::EngineTier)" :total="$engines->requests" :labels="$tierLabels" />
                <x-breakdown :title="__('Pages AI assistants fetched')" :unit="__('requests')" :rows="$period->top(CountKind::EnginePage)" :total="$engines->requests" />
                <x-breakdown :title="__('Search engines')" :unit="__('requests, by crawler')" :rows="$period->top(CountKind::SearchEngine)" :total="$period->group(TrafficGroup::SearchEngines)->requests" />
                <x-breakdown :title="__('Bots and scanners')" :unit="__('requests, by name or reason')" :rows="$period->top(CountKind::BotAgent)" :total="$period->group(TrafficGroup::Bots)->requests" />
                <x-breakdown :title="__('Countries')" :unit="__('visitors (from the log\'s own country field)')" :rows="$period->top(CountKind::PeopleCountry)" :total="$people->readers" :labels="['Unknown' => __('Not recorded')]" />
                <x-breakdown :title="__('Devices')" :unit="__('visitors')" :rows="$period->top(CountKind::PeopleDevice)" :total="$people->readers" />
                <x-breakdown :title="__('Browsers')" :unit="__('visitors')" :rows="$period->top(CountKind::PeopleBrowser)" :total="$people->readers" />
                <x-breakdown :title="__('Campaigns')" :unit="__('visitors, by their utm_ tags')" :rows="$period->top(CountKind::PeopleCampaign)" :total="$people->readers" />
            </div>
        </div>
    @endif
</x-layouts.app>
