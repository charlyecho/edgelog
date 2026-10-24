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
                <x-timezone-select :value="$site->timezone" />
                <p class="-mt-1 text-xs text-ink-3">{{ __('A day is cut at midnight in this zone. Days already counted keep the zone they were counted in.') }}</p>
                <x-select :label="__('Log format')" name="log_format">
                    @foreach (\App\Enums\LogFormat::cases() as $format)
                        <option value="{{ $format->value }}" @selected(old('log_format', $site->log_format->value) === $format->value)>{{ $format->label() }}</option>
                    @endforeach
                </x-select>
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

        <x-card :title="__('Send the log')" :description="__('A small program on the site\'s server, edgelog-ship, sends the access log\'s new lines here as they are written, with the site token. Lines are kept once, even when a batch is sent twice, and none is lost while the server is unreachable.')">
            <ol class="grid gap-4">
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">1.</span> {{ __('Install the shipper on the site\'s server. It is one shell script: nothing else to install on Debian or Ubuntu, it uses curl or wget.') }}</p>
                    <x-code :code="$setup->installCommands()" />
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">2.</span> {{ __('Keep the site token in a file only the shipper reads: paste the token after the second command, then press Ctrl-D.') }}</p>
                    <x-code :code="$setup->tokenCommands()" />
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">3.</span> {{ __('Run it as a service. Change --log if the access log is somewhere else.') }}</p>
                    <x-code :code="$setup->serviceCommand()" />
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">4.</span> {{ __('Start it, then come back here to see the first lines arrive:') }}</p>
                    <x-code :code="$setup->startCommands()" />
                </li>
            </ol>

            @if ($site->log_format === \App\Enums\LogFormat::NginxExtended)
                <div class="mt-4 grid gap-1.5 border-t border-grid pt-4">
                    <p>{{ __('The extended format: add this to nginx\'s http block, then use it on the site\'s access_log line (access_log /var/log/nginx/access.log edgelog_extended;).') }}</p>
                    <x-code :code="\App\Ingest\Parsers\ExtendedLogParser::NGINX_LOG_FORMAT" />
                </div>
            @endif

            <x-slot:after>
                <p class="text-ink-2">{{ __('Address') }} <code class="ml-1 rounded-md bg-sunk px-1.5 py-0.5 font-mono text-[13px] text-ink">{{ $setup->ingestUrl() }}</code></p>
            </x-slot:after>
        </x-card>

        <x-card :title="__('Connection')" :description="__('What the address last answered for this site.')">
            @php($status = $site->ingestStatus)

            @if ($status === null)
                <p class="text-ink-2">{{ __('Nothing received yet.') }}</p>
            @else
                <dl class="grid gap-x-6 gap-y-1.5 sm:grid-cols-[auto_1fr]">
                    <dt class="font-semibold">{{ __('Last batch') }}</dt>
                    <dd class="text-ink-2">
                        @if ($status->last_batch_at)
                            {{ trans_choice(':when: :count line kept|:when: :count lines kept', $status->last_batch_lines, ['when' => $status->last_batch_at->diffForHumans()]) }},
                            {{ trans_choice(':count refused.|:count refused.', $status->last_batch_rejected) }}
                        @else
                            {{ __('None yet.') }}
                        @endif
                    </dd>

                    <dt class="font-semibold">{{ __('Today (UTC)') }}</dt>
                    <dd class="text-ink-2">{{ trans_choice(':count line|:count lines', $status->today?->isToday() ? $status->today_lines : 0) }}</dd>

                    @if ($status->last_refusal_reason)
                        <dt @class(['font-semibold', 'text-bad' => $status->isRefusing()])>{{ __('Last refusal') }}</dt>
                        <dd @class(['text-ink-2', 'text-bad' => $status->isRefusing()])>
                            {{ __(':when (:status): :words', ['when' => $status->last_refusal_at->diffForHumans(), 'status' => $status->last_refusal_status, 'words' => $status->last_refusal_reason->words()]) }}
                        </dd>
                    @endif
                </dl>
            @endif

            <div class="mt-4"><x-button variant="secondary" size="sm" :href="route('teams.sites.edit', [$team, $site])">{{ __('Check again') }}</x-button></div>
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
