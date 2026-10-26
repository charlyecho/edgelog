<x-layouts.app :title="__('Site settings')" :lede="__('The address, the token the site sends its data with, and the team it belongs to.')">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
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

        <x-card :title="__('Icon')" :description="__('Shown next to the site\'s name. It is read from the site when the site is added, or you can upload one.')">
            <div class="flex flex-wrap items-center gap-4">
                <x-tile :label="$site->name" :seed="$site->uuid" :src="$site->iconUrl($team)" size="size-12 text-xl" />
                <p class="text-ink-2">
                    @if ($site->icon_path === null)
                        {{ __('No icon: the site is shown with its initial.') }}
                    @elseif ($site->icon_uploaded)
                        {{ __('Uploaded :when.', ['when' => $site->icon_changed_at->diffForHumans()]) }}
                    @else
                        {{ __('Read from the site :when.', ['when' => $site->icon_changed_at->diffForHumans()]) }}
                    @endif
                </p>
            </div>

            <form method="POST" action="{{ route('teams.sites.icon.store', [$team, $site]) }}" enctype="multipart/form-data" class="mt-4 grid gap-1.5">
                @csrf
                <label class="grid gap-1.5 text-[13px] font-medium text-ink-2">
                    {{ __('Upload an icon') }}
                    <span class="flex flex-wrap items-center gap-3">
                        <input type="file" name="icon" accept=".png,.jpg,.jpeg,.gif,.webp,.svg,.ico" required class="min-w-0 flex-1 text-[13px] font-normal text-ink file:mr-3 file:h-8 file:cursor-pointer file:rounded-lg file:border file:border-rule file:bg-surface file:px-3 file:text-[13px] file:font-semibold file:text-ink hover:file:border-ink-3">
                        <x-button variant="secondary" size="sm">{{ __('Upload') }}</x-button>
                    </span>
                    <span class="text-xs font-normal text-ink-3">{{ __('PNG, JPEG, GIF, WebP, SVG or ICO, :size KB at most. A square image of 64 pixels or more looks best.', ['size' => \App\Models\Site::ICON_MAX_BYTES / 1024]) }}</span>
                    @error('icon')
                        <span class="text-[13px] font-normal text-bad">{{ $message }}</span>
                    @enderror
                </label>
            </form>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-grid pt-4">
                <form method="POST" action="{{ route('teams.sites.icon.fetch', [$team, $site]) }}" @if ($site->icon_uploaded) onsubmit="return confirm(@js(__('Read the icon from the site? It replaces the uploaded one.')))" @endif>
                    @csrf
                    <x-button variant="secondary" size="sm">{{ __('Read from the site') }}</x-button>
                </form>
                @if ($site->icon_path !== null)
                    <form method="POST" action="{{ route('teams.sites.icon.destroy', [$team, $site]) }}">
                        @csrf
                        @method('DELETE')
                        <x-button variant="danger" size="sm">{{ __('Remove the icon') }}</x-button>
                    </form>
                @endif
            </div>
        </x-card>

        <x-card :title="__('Paths')" :description="__('Two lists that change how requests are counted: one path per line, * stands for any characters.')">
            <form method="POST" action="{{ route('teams.sites.paths.update', [$team, $site]) }}" class="grid gap-4">
                @csrf
                @method('PUT')
                @foreach ([
                    'not_pages' => [__('Paths that are not pages'), __('Requests your own pages make while they are read, such as a dashboard loading its data. A request to one is part of its visit (it keeps it going and counts in its length) but never a page: not in pages read, the visitor flow, where visits ended or the steps between pages. Requests under /api/ are never pages already.'), '/user/home_ajax/*'],
                    'own_paths' => [__('Paths the site really has'), __('Paths scanners ask for that your site really has, such as its own /admin/ or an old site\'s /wp-content/uploads/. A request under one is never a scanner probe: the people who use them stay visitors, and what they were answered is never taken for an exposure.'), '/admin/*'],
                ] as $name => [$label, $hint, $placeholder])
                    <label class="grid gap-1.5 text-[13px] font-medium text-ink-2">
                        <span class="font-semibold text-ink">{{ $label }}</span>
                        <span class="font-normal text-ink-3">{{ $hint }}</span>
                        <textarea name="{{ $name }}" rows="3" placeholder="{{ $placeholder }}" @error($name) aria-invalid="true" @enderror @class([
                            'w-full rounded-[9px] border bg-surface px-3 py-2 font-mono text-[13px] font-normal text-ink placeholder:text-ink-3 hover:border-ink-3',
                            'border-bad' => $errors->has($name),
                            'border-rule' => ! $errors->has($name),
                        ])>{{ old($name, implode("\n", $site->{$name} ?? [])) }}</textarea>
                        @error($name)
                            <span class="text-[13px] font-normal text-bad">{{ $message }}</span>
                        @enderror
                    </label>
                @endforeach
                <label class="flex items-center gap-2 text-[13px] text-ink-2">
                    <input type="hidden" name="recount" value="0">
                    <input type="checkbox" name="recount" value="1" class="size-4" @checked(old('recount'))>
                    {{ __('Count the last :count days again with these paths (older days keep their figures)', ['count' => \App\Http\Controllers\SitePathsController::RECOUNT_DAYS]) }}
                </label>
                <div><x-button variant="secondary">{{ __('Save') }}</x-button></div>
            </form>
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

            @if ($lastDay?->lacksStylesheets())
                <x-notice tone="warn" class="mt-4" :title="__('The log has no stylesheets')">
                    <div class="grid gap-1.5">
                        <p>{{ __('On :day, :count requests and not one stylesheet: the web server seems to leave .css and .js files out of the log (access_log off). Edgelog tells a person, who sees the page, from a script, which only fetches it, by the files the page asks for; without them it can only go by images, and counts too many bots.', ['day' => $lastDay->day->isoFormat('LL'), 'count' => \Illuminate\Support\Number::format($lastDay->hits)]) }}</p>
                        <p>{{ __('Keep stylesheets and scripts in the log; images and fonts may stay out. For nginx, in the location that serves them:') }}</p>
                        <x-code :code="\App\Ingest\ShipperSetup::nginxAssetLogging()" />
                        <p class="text-xs text-ink-3">{{ __('If the site\'s pages have no stylesheet at all, there is nothing to change.') }}</p>
                    </div>
                </x-notice>
            @endif

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

        <x-card :title="__('Past logs')" :description="__('The web server keeps its older access logs (access.log.1, access.log.2.gz...). The shipper can send them once, so the days before it started are counted too.')">
            <div class="grid gap-1.5">
                <p>{{ __('Once the shipper runs, send them from the site\'s server. Only lines older than the first one it shipped are kept, so nothing is counted twice, and running the command again goes on where it stopped.') }}</p>
                <x-code :code="$setup->backfillCommand()" />
                <p class="text-xs text-ink-3">{{ __('A gzip\'d file is unpacked in /var/lib/edgelog-ship while it is sent: leave room there for the largest one. The days are counted a few minutes after the last part arrives. Robots.txt, sitemap and stack checks cannot be read for past days.') }}</p>
            </div>

            <x-slot:after>
                @if ($history['files'] === 0)
                    <p class="text-ink-2">{{ __('No past log received yet.') }}</p>
                @else
                    <p class="text-ink-2">
                        {{ trans_choice(':count file|:count files', $history['files']) }},
                        {{ trans_choice(':count past line kept|:count past lines kept', $history['lines']) }}@if ($history['first']),
                            {{ __('from :first to :last', ['first' => \Carbon\CarbonImmutable::parse($history['first'])->isoFormat('LL'), 'last' => \Carbon\CarbonImmutable::parse($history['last'])->isoFormat('LL')]) }}@endif.
                        {{ $history['counting'] ? __('Its days are waiting to be counted.') : __('Its days are counted.') }}
                    </p>
                @endif
            </x-slot:after>
        </x-card>

        <x-card :title="__('Signed-in users')" :description="__('When your app knows who is signed in, it can name them in the log: Edgelog then counts signed-in users across days and devices, and links their payments to where they first came from.')">
            <ol class="grid gap-4">
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">1.</span> {{ __('Your app sends a pseudonymous id of the signed-in user in the :header response header. Never the raw id or an email: a keyed hash, as here.', ['header' => \App\Ingest\UserTrackingSetup::HEADER]) }}</p>
                    <x-code :code="\App\Ingest\UserTrackingSetup::laravel()" />
                    <p class="text-xs text-ink-3">{{ __('Without Laravel:') }}</p>
                    <x-code :code="\App\Ingest\UserTrackingSetup::php()" />
                </li>
                <li class="grid gap-1.5">
                    <p><span class="font-semibold">2.</span> {{ __('The web server writes it at the end of the log line as uid=, and takes it off the response, so the browser never sees it.') }}</p>
                    <x-code :code="\App\Ingest\UserTrackingSetup::nginx()" />
                    <p class="text-xs text-ink-3">{{ __('Apache:') }}</p>
                    <x-code :code="\App\Ingest\UserTrackingSetup::apache()" />
                </li>
                <li>
                    <p><span class="font-semibold">3.</span> {{ __('For Stripe, pass the same id as the Checkout Session\'s client_reference_id (or metadata.uid, and the subscription\'s metadata.uid).') }}</p>
                </li>
            </ol>

            <x-slot:after>
                <p class="text-[13px] text-ink-3">{{ __('A pseudonymous id is still personal data: say so in your privacy notice. Ids are kept :days days.', ['days' => config('analytics.users_kept_days')]) }}</p>
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

        <x-card :title="__('Revenue from Stripe')" :description="__('Stripe sends a signed event for each payment and refund; the site\'s revenue is counted by day and by currency, live payments only. No customer name, email or address is kept.')">
            <ol class="grid gap-3">
                <li>
                    <p><span class="font-semibold">1.</span> {{ __('In Stripe, Developers > Webhooks > Add endpoint, with this address:') }}</p>
                    <x-copy-field :value="route('hooks.stripe', $site)" />
                </li>
                <li>
                    <p><span class="font-semibold">2.</span> {{ __('Select these events:') }}</p>
                    <x-code :code="implode(\PHP_EOL, \App\Integrations\Stripe\StripeEvents::TYPES)" class="mt-2" />
                </li>
                <li>
                    <p><span class="font-semibold">3.</span> {{ __('Copy the endpoint\'s signing secret (whsec_...) here:') }}</p>
                    <form method="POST" action="{{ route('teams.sites.stripe.update', [$team, $site]) }}" class="mt-2 flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PUT')
                        <div class="min-w-64 flex-1">
                            <x-input :label="__('Signing secret')" name="secret" type="password" autocomplete="off" :placeholder="$stripe ? __('Saved. Paste a new one to replace it.') : 'whsec_...'" required />
                        </div>
                        <x-button variant="secondary">{{ $stripe ? __('Replace the secret') : __('Connect Stripe') }}</x-button>
                    </form>
                </li>
            </ol>

            @if ($stripe)
                <div class="mt-4 grid gap-1.5 border-t border-grid pt-4 text-[13px]">
                    <p class="text-ink-2">
                        {{ __('Last event received:') }}
                        <span class="font-semibold text-ink">{{ $stripe->last_received_at?->diffForHumans() ?? __('none yet') }}</span>
                    </p>
                    @if ($stripe->last_refusal && (! $stripe->last_received_at || $stripe->last_refused_at?->greaterThan($stripe->last_received_at)))
                        <p class="text-bad">{{ __('Last refusal, :when: :why', ['when' => $stripe->last_refused_at->diffForHumans(), 'why' => $stripe->last_refusal]) }}</p>
                    @endif
                    @foreach ($stripeEvents as $event)
                        <p class="font-mono text-[12px] text-ink-3">
                            {{ $event->occurred_at->setTimezone($site->timezone)->format('Y-m-d H:i') }} · {{ $event->type }}
                            @unless ($event->livemode) · {{ __('test mode, not counted') }} @endunless
                        </p>
                    @endforeach
                    <form method="POST" action="{{ route('teams.sites.stripe.destroy', [$team, $site]) }}" class="mt-2" onsubmit="return confirm(@js(__('Disconnect Stripe? Its address stops taking events; the revenue already counted stays.')))">
                        @csrf
                        @method('DELETE')
                        <x-button variant="danger" size="sm">{{ __('Disconnect Stripe') }}</x-button>
                    </form>
                </div>
            @endif
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
