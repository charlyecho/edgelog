<x-layouts.app :title="__('Site settings')" :lede="__('The address, the token the site sends its data with, and the team it belongs to.')">
    <x-slot:trail>
        <x-crumb :href="route('teams.show', $team)">{{ $team->name }}</x-crumb>
        <x-crumb :href="route('teams.sites.show', [$team, $site])">{{ $site->name }}</x-crumb>
    </x-slot:trail>

    <div class="grid max-w-3xl gap-8">
        @if (session('site_token'))
            <x-notice tone="warn" :title="__('Site token (shown once)')">
                {{ __('Copy it now: only its hash is kept, so it cannot be shown again. If you lose it, generate a new one.') }}
                <x-copy-field :value="session('site_token')" />
            </x-notice>
        @endif

        <x-card :title="__('Site')">
            <form method="POST" action="{{ route('teams.sites.update', [$team, $site]) }}" class="grid gap-3">
                @csrf
                @method('PUT')
                <x-input :label="__('URL')" name="url" :value="$site->url" required />
                <x-input :label="__('Name')" name="name" :value="$site->name" />
                <div><x-button variant="secondary">{{ __('Save') }}</x-button></div>
            </form>

            <x-slot:after>
                <p class="text-ink-2">{{ __('Site ID') }} <code class="ml-1 rounded-md bg-sunk px-1.5 py-0.5 font-mono text-[13px] text-ink">{{ $site->uuid }}</code></p>
            </x-slot:after>
        </x-card>

        <x-card :title="__('Token')" :description="__('The site sends its data with this token. Generating a new one stops the previous one at once.')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-ink-2">{{ __('Generated :when.', ['when' => $site->token_generated_at->diffForHumans()]) }}</p>
                <form method="POST" action="{{ route('teams.sites.token.store', [$team, $site]) }}" onsubmit="return confirm(@js(__('Generate a new token? The current one will stop working.')))">
                    @csrf
                    <x-button variant="secondary">{{ __('Generate a new token') }}</x-button>
                </form>
            </div>
        </x-card>

        <x-card :title="__('Team')" :description="__('The site belongs to :team. You can move it to another team where you are an owner or admin.', ['team' => $team->name])">
            @if ($otherTeams->isEmpty())
                <p class="text-ink-2">{{ __('You manage sites in no other team.') }}</p>
            @else
                <form method="POST" action="{{ route('teams.sites.team.update', [$team, $site]) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <x-select :label="__('Move to')" name="team">
                        @foreach ($otherTeams as $other)
                            <option value="{{ $other->uuid }}">{{ $other->name }}</option>
                        @endforeach
                    </x-select>
                    <x-button variant="secondary">{{ __('Move the site') }}</x-button>
                </form>
            @endif
        </x-card>

        @can('delete', $site)
            <x-card :title="__('Delete the site')" :description="__('The site and its token are deleted for good.')">
                <form method="POST" action="{{ route('teams.sites.destroy', [$team, $site]) }}" onsubmit="return confirm(@js(__('Delete :site for good?', ['site' => $site->name])))">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">{{ __('Delete the site') }}</x-button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
