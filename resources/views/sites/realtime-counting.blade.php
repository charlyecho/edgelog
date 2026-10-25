<x-layouts.app :title="__('Overview')">
    <x-overview-tabs :team="$team" :site="$site" current="realtime" />

    <div data-live-refresh="15">
        <x-notice tone="warn" :title="__('Today is being counted')">
            {{ __('The queue is reading today\'s log; this page shows it as soon as it is done.') }}
            <span data-live-state></span>
        </x-notice>
        <button type="button" class="hidden" data-live-pause data-pause="" data-resume="" data-paused="" data-running=""></button>
    </div>
</x-layouts.app>
