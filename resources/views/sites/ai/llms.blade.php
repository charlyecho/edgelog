<x-section-frame section="ai" :team="$team" :site="$site" current="llms" :title="__('llms.txt')"
    :lede="__('A plain list of the site\'s best pages for AI assistants, built from what people and assistants read most. Preview it, copy it or download it, then publish it yourself.')">
    <section class="rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-[14px] font-bold">{{ __('Suggested llms.txt') }}</h3>
                <p class="text-xs text-ink-3">{{ __('Edgelog never writes to your website.') }}</p>
            </div>
            <div class="flex gap-2">
                <x-button type="button" variant="secondary" size="sm" data-copy="#llms" :data-copied="__('Copied')">{{ __('Copy') }}</x-button>
                <x-button variant="secondary" size="sm" :href="route('teams.sites.ai.llms.download', [$team, $site])">{{ __('Download') }}</x-button>
            </div>
        </header>
        <p class="mb-3 max-w-[92ch] text-[13px] text-ink-2">
            {{ __('The pages people and AI assistants read most, :from to :to (:count recorded days), by section. The log gives no page titles, so each link is named by its path: put the titles in. Write the summary line yourself, check the links, then publish it at /llms.txt.', ['from' => $period->first->translatedFormat('D j M Y'), 'to' => $period->last->translatedFormat('D j M Y'), 'count' => $period->verified()->count()]) }}
        </p>
        <pre id="llms" class="max-h-[32rem] overflow-auto rounded-lg bg-sunk p-3 font-mono text-xs whitespace-pre-wrap text-ink">{{ $llms }}</pre>
    </section>
</x-section-frame>
