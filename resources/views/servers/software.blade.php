@php
    use App\Analytics\Stack\ServerSoftware;

    $software = $server->software;
    $system = $software['os_status'] ?? null;
    $state = $software['system'] ?? null;
    $ssh = ServerSoftware::sshSettings($state);
    $exposed = ServerSoftware::exposedServices($state);
    $alerts = ServerSoftware::alerts($software);
    $severityClass = fn (?string $severity) => match ($severity) {
        'critical', 'high' => 'bg-bad text-white',
        'medium' => 'bg-warn text-white',
        default => 'bg-sunk text-ink-2',
    };
    $severityWords = ['critical' => __('Critical'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low'), 'negligible' => __('Negligible')];
    $statusWords = [
        'current' => [__('newest of its branch'), 'text-good'],
        'update' => [__('newer release out'), 'text-warn'],
        'eol' => [__('branch no longer supported'), 'text-bad'],
        'not_listed' => [__('branch not listed on endoflife.date'), 'text-ink-3'],
        'check_version' => [__('version not read'), 'text-ink-3'],
        'distro' => [__('built by the system, supported with it'), 'text-ink-2'],
    ];
    $yesNo = fn (?bool $value, string $yes, string $no, bool $goodWhenYes) => $value === null
        ? ['—', 'text-ink-3']
        : [$value ? $yes : $no, $value === $goodWhenYes ? 'text-good' : 'text-warn'];
    $unattended = $yesNo($state['unattended_upgrades'] ?? null, __('installed by themselves'), __('not installed by themselves'), true);
    $timezone = $server->timezone();
@endphp

<x-card :title="__('What runs on it')" :description="__('Read by edgelog-ship --metrics once an hour; each version is set against its end of life (endoflife.date) and its known flaws (CVE) once a day: from the system\'s security tracker (OSV.dev) for a package of Ubuntu or Debian, which fix flaws without changing the version, from the NVD for any other build.')" flush>
    @if ($software === null)
        <p class="p-4 text-ink-2">{{ __('Nothing read yet: edgelog-ship 1.4.0 or newer, started with --metrics, says what runs on the machine with its first sample, then once an hour.') }}</p>
    @else
        <div class="grid gap-3 border-b border-grid p-4 text-[13px]">
            <p class="text-ink-2">
                {{ __('Read :read', ['read' => $server->software_read_at?->setTimezone($timezone)->translatedFormat('j M H:i') ?? '—']) }}
                ·
                {{ $server->software_checked_at === null ? __('not checked yet') : __('checked :checked', ['checked' => $server->software_checked_at->setTimezone($timezone)->translatedFormat('j M H:i')]) }}
            </p>
            <x-alert-chips :alerts="$alerts" />

            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                @if ($software['os'] !== null)
                    <div>
                        <dt class="font-semibold">{{ __('System') }}</dt>
                        <dd class="text-ink-2">
                            {{ (ServerSoftware::SYSTEMS[$software['os']] ?? $software['os']).' '.$software['os_version'] }}
                            @if ($system !== null && $system['status'] === 'eol')
                                · <span class="text-bad">{{ __('no longer supported since :date', ['date' => $system['eol']]) }}</span>
                            @elseif ($system !== null && $system['status'] === 'current')
                                · <span class="text-good">{{ $system['eol'] ? __('supported until :date', ['date' => $system['eol']]) : __('supported') }}</span>
                            @endif
                        </dd>
                    </div>
                @endif

                @if ($state !== null)
                    <div>
                        <dt class="font-semibold">{{ __('Updates waiting') }}</dt>
                        <dd class="text-ink-2">
                            @if ($state['updates'] === null)
                                {{ __('not read (no apt)') }}
                            @else
                                <span @class(['text-warn' => $state['security_updates'] > 0, 'text-good' => $state['updates'] === 0])>{{ trans_choice(':count update|:count updates', $state['updates']) }}, {{ trans_choice(':count for security|:count for security', $state['security_updates'] ?? 0) }}</span>
                            @endif
                            · <span class="{{ $unattended[1] }}">{{ __('security updates :words', ['words' => $unattended[0]]) }}</span>
                        </dd>
                    </div>

                    <div>
                        <dt class="font-semibold">{{ __('Reboot') }}</dt>
                        <dd class="text-ink-2">
                            @if ($state['reboot_required'] === true)
                                <span class="text-warn">{{ __('needed') }}</span>@if ($state['reboot_packages'] !== []) · {{ implode(', ', $state['reboot_packages']) }}@endif
                            @elseif ($state['reboot_required'] === false)
                                <span class="text-good">{{ __('not needed') }}</span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="font-semibold">{{ __('Services') }}</dt>
                        <dd class="text-ink-2">
                            @if ($state['failed_units'] === null)
                                {{ __('not read (no systemd)') }}
                            @elseif ($state['failed_units'] === [])
                                <span class="text-good">{{ __('none failed') }}</span>
                            @else
                                <span class="text-bad">{{ __('failed: :units', ['units' => implode(', ', $state['failed_units'])]) }}</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="font-semibold">{{ __('SSH') }}</dt>
                        <dd class="text-ink-2">
                            @if ($ssh === null)
                                {{ __('no sshd configuration read') }}
                            @else
                                <span @class(['text-warn' => $ssh['password_authentication'] === 'yes', 'text-good' => $ssh['password_authentication'] === 'no'])>{{ __('passwords: :value', ['value' => $ssh['password_authentication'] ?? __('unknown')]) }}</span>
                                · <span @class(['text-warn' => $ssh['permit_root_login'] === 'yes'])>{{ __('root login: :value', ['value' => $ssh['permit_root_login'] ?? __('unknown')]) }}</span>
                                @if ($state['ssh']['partial'])
                                    <span class="block text-xs text-ink-3">{{ __('Some files sshd includes cannot be read by edgelog-ship: a setting they hold is not known.') }}</span>
                                @endif
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="font-semibold">{{ __('Listening to the network') }}</dt>
                        <dd class="text-ink-2">
                            @if ($state['listening'] === null)
                                {{ __('not read (no ss)') }}
                            @elseif ($state['listening'] === [])
                                {{ __('nothing but the machine itself') }}
                            @else
                                <span class="font-mono text-xs">{{ implode(' ', $state['listening']) }}</span>
                                @foreach ($exposed as $service)
                                    <span class="block text-warn">{{ __(':service on :address', ['service' => $service['service'], 'address' => $service['address']]) }}</span>
                                @endforeach
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        @forelse ($software['programs'] as $program)
            @php
                $flaws = $program['flaws'] ?? null;
                $fixable = ServerSoftware::fixable($program);
                $status = $program['status'] ?? null;
            @endphp
            <div class="border-b border-grid px-4 py-3 text-[13px] last:border-b-0">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-bold">{{ ServerSoftware::label($program) }}</span>
                    @if ($program['package_version'] !== null)
                        <code class="font-mono text-xs text-ink-3">{{ $program['package'] }} {{ $program['package_version'] }}</code>
                    @endif
                    @if ($status !== null && isset($statusWords[$status]))
                        <span class="{{ $statusWords[$status][1] }}">
                            {{ $statusWords[$status][0] }}@if ($status === 'update' && $program['latest']) ({{ $program['latest'] }})@elseif ($status === 'eol' && $program['newest']) · {{ __('newest: :version', ['version' => $program['newest']]) }}@endif
                        </span>
                    @endif
                    <span class="ml-auto text-ink-3">
                        @if (in_array($program['product'], ServerSoftware::FLAWS_NOT_READ, true))
                            {{ __('flaws: see the security updates and the reboot') }}
                        @elseif ($flaws === null)
                            {{ __('flaws not checked yet') }}
                        @elseif ($flaws === [])
                            <span class="text-good">{{ __('no known flaw') }}</span>
                        @elseif (($program['source'] ?? null) === 'distro')
                            {{ trans_choice(':count known flaw|:count known flaws', count($flaws)) }} · {{ trans_choice(':count fixed in a newer build|:count fixed in a newer build', count($fixable)) }}
                        @else
                            {{ trans_choice(':count known flaw|:count known flaws', count($flaws)) }}
                        @endif
                    </span>
                </div>

                @if (($program['settings'] ?? null) !== null)
                    <p class="mt-1 text-xs text-ink-3">
                        {{ __('PHP-FPM:') }}
                        @foreach ($program['settings'] as $name => $value)
                            <span @class(['text-warn' => $name === 'display_errors' && ServerSoftware::isOn($value)])>{{ $name }} {{ $value }}</span>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                @endif

                @if ($flaws !== null && $flaws !== [])
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs text-ink-3">{{ __('The flaws, worst first') }}</summary>
                        <ul class="mt-2 grid gap-1">
                            @foreach ($flaws as $flaw)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span class="w-[72px] rounded px-1.5 py-0.5 text-center text-[11px] font-bold {{ $severityClass($flaw['severity']) }}">{{ $severityWords[$flaw['severity']] ?? __('Not rated') }}</span>
                                    <a href="https://nvd.nist.gov/vuln/detail/{{ $flaw['id'] }}" target="_blank" rel="noopener noreferrer" class="font-mono text-xs">{{ $flaw['id'] }}</a>
                                    @if ($flaw['score'] !== null)
                                        <span class="text-xs text-ink-3">{{ number_format($flaw['score'], 1) }}</span>
                                    @endif
                                    @if (($program['source'] ?? null) === 'distro')
                                        <span class="text-xs {{ $flaw['fixed'] ? 'text-ink-2' : 'text-ink-3' }}">{{ $flaw['fixed'] ? __('fixed in :build', ['build' => $flaw['fixed']]) : __('no fix yet') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @empty
            <p class="p-4 text-ink-2">{{ __('No known program found on the machine.') }}</p>
        @endforelse
    @endif
</x-card>
