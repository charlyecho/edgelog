@props(['team', 'sites', 'servers', 'limited' => false, 'grantedSites' => [], 'grantedServers' => []])

{{-- What a viewer may see: everything, or the sites and servers ticked, each on its own. --}}
@php
    $limited = old('access', $limited ? 'limited' : 'all') === 'limited';
    $grantedSites = old('sites', $grantedSites);
    $grantedServers = old('servers', $grantedServers);
@endphp

<fieldset class="grid gap-3">
    <legend class="sr-only">{{ __('Access') }}</legend>

    @if ($errors->has('access') || $errors->has('sites.*') || $errors->has('servers.*'))
        <p class="text-bad">{{ __('A site or server ticked is no longer in the team. Tick them again.') }}</p>
    @endif

    <label class="flex items-start gap-2.5">
        <input type="radio" name="access" value="all" class="mt-1 size-4 accent-[var(--ink)]" @checked(! $limited)>
        <span>
            <span class="block font-semibold">{{ __('Every site and server of the team') }}</span>
            <span class="block text-[13px] text-ink-2">{{ __('Including those added later.') }}</span>
        </span>
    </label>

    <label class="flex items-start gap-2.5">
        <input type="radio" name="access" value="limited" class="mt-1 size-4 accent-[var(--ink)]" @checked($limited)>
        <span>
            <span class="block font-semibold">{{ __('Only the sites and servers ticked') }}</span>
            <span class="block text-[13px] text-ink-2">{{ __('A server and its sites are granted apart: a site gives nothing of its server, a server nothing of its sites.') }}</span>
        </span>
    </label>

    <div class="grid gap-4 border-t border-grid pt-3 pl-6.5 sm:grid-cols-2">
        <div class="grid content-start gap-1.5">
            <p class="text-[13px] font-semibold text-ink-2">{{ __('Sites') }}</p>
            @forelse ($sites as $site)
                <label class="flex min-w-0 items-center gap-2.5">
                    <input type="checkbox" name="sites[]" value="{{ $site->uuid }}" class="size-4 accent-[var(--ink)]" @checked(in_array($site->uuid, $grantedSites, true))>
                    <x-tile :label="$site->name" :seed="$site->uuid" :src="$site->iconUrl($team)" size="size-5 text-[10px]" />
                    <span class="truncate">{{ $site->name }}</span>
                </label>
            @empty
                <p class="text-[13px] text-ink-3">{{ __('No site yet.') }}</p>
            @endforelse
        </div>

        <div class="grid content-start gap-1.5">
            <p class="text-[13px] font-semibold text-ink-2">{{ __('Servers') }}</p>
            @forelse ($servers as $server)
                <label class="flex min-w-0 items-center gap-2.5">
                    <input type="checkbox" name="servers[]" value="{{ $server->uuid }}" class="size-4 accent-[var(--ink)]" @checked(in_array($server->uuid, $grantedServers, true))>
                    <x-icon name="server" class="text-ink-3" />
                    <span class="truncate">{{ $server->name() }}</span>
                    @if ($server->sites->isNotEmpty())
                        <span class="truncate text-[13px] text-ink-3">· {{ $server->sites->pluck('name')->join(', ') }}</span>
                    @endif
                </label>
            @empty
                <p class="text-[13px] text-ink-3">{{ __('No server yet.') }}</p>
            @endforelse
        </div>
    </div>
</fieldset>
