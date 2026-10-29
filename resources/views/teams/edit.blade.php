<x-layouts.app :title="__('Team')" :lede="__('Who may see :team\'s sites, and what they may change.', ['team' => $team->name])">
    <x-slot:trail>
        <x-crumb :href="route('teams.sites.index', $team)">{{ $team->name }}</x-crumb>
    </x-slot:trail>

    <div class="grid max-w-5xl gap-8">
        @if (session('invitation_url'))
            <x-notice tone="warn" :title="__('Invitation link (shown once)')">
                {{ __('It was mailed to the person. If the mail does not arrive, pass this link on yourself. It works once, for :days days.', ['days' => \App\Models\TeamInvitation::VALID_DAYS]) }}
                <x-copy-field :value="session('invitation_url')" />
            </x-notice>
        @endif

        @error('member')
            <x-notice tone="bad">{{ $message }}</x-notice>
        @enderror

        <x-card :title="__('Name')">
            @can('update', $team)
                <form method="POST" action="{{ route('teams.update', $team) }}" class="flex items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div class="flex-1">
                        <x-input :label="__('Team name')" name="name" :value="$team->name" required />
                    </div>
                    <x-button variant="secondary">{{ __('Save') }}</x-button>
                </form>
            @else
                <p>{{ $team->name }}</p>
            @endcan
        </x-card>

        <x-card :title="__('Icon')" :description="__('Shown next to the team\'s name.')">
            <div class="flex flex-wrap items-center gap-4">
                <x-tile :label="$team->name" :seed="$team->uuid" :src="$team->iconUrl()" size="size-12 text-xl" />
                <p class="text-ink-2">
                    {{ $team->icon_path === null ? __('No icon: the team is shown with its initial.') : __('Uploaded :when.', ['when' => $team->icon_changed_at->diffForHumans()]) }}
                </p>
            </div>

            @can('update', $team)
                <form method="POST" action="{{ route('teams.icon.store', $team) }}" enctype="multipart/form-data" class="mt-4 grid gap-1.5">
                    @csrf
                    <label class="grid gap-1.5 text-[13px] font-medium text-ink-2">
                        {{ __('Upload an icon') }}
                        <span class="flex flex-wrap items-center gap-3">
                            <input type="file" name="icon" accept=".png,.jpg,.jpeg,.gif,.webp,.svg,.ico" required class="min-w-0 flex-1 text-[13px] font-normal text-ink file:mr-3 file:h-8 file:cursor-pointer file:rounded-lg file:border file:border-rule file:bg-surface file:px-3 file:text-[13px] file:font-semibold file:text-ink hover:file:border-ink-3">
                            <x-button variant="secondary" size="sm">{{ __('Upload') }}</x-button>
                        </span>
                        <span class="text-xs font-normal text-ink-3">{{ __('PNG, JPEG, GIF, WebP, SVG or ICO, :size KB at most. A square image of 64 pixels or more looks best.', ['size' => \App\Models\Team::ICON_MAX_BYTES / 1024]) }}</span>
                        @error('icon')
                            <span class="text-[13px] font-normal text-bad">{{ $message }}</span>
                        @enderror
                    </label>
                </form>

                @if ($team->icon_path !== null)
                    <form method="POST" action="{{ route('teams.icon.destroy', $team) }}" class="mt-4 border-t border-grid pt-4">
                        @csrf
                        @method('DELETE')
                        <x-button variant="danger" size="sm">{{ __('Remove the icon') }}</x-button>
                    </form>
                @endif
            @endcan
        </x-card>

        <x-card :title="__('Members')" :description="trans_choice(':count member. Your role: :role.|:count members. Your role: :role.', $team->members->count(), ['role' => $role->label()])" flush>
            @error('role')
                <p class="border-b border-rule px-4 py-2 text-bad">{{ $message }}</p>
            @enderror

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="border-b border-rule text-[13px] text-ink-2">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">{{ __('Email') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ __('Name') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ __('Role') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ __('Access') }}</th>
                            <th class="px-4 py-2.5 font-semibold">{{ __('Two-step sign-in') }}</th>
                            <th class="px-4 py-2.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-grid">
                        @foreach ($team->members as $member)
                            @php($isYou = $member->is(auth()->user()))
                            <tr>
                                <td class="px-4 py-3 font-mono text-[13px]">
                                    {{ $member->email }}
                                    @if ($isYou)
                                        <span class="font-sans text-ink-3">{{ __('(you)') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $member->name }}</td>
                                <td class="px-4 py-3">
                                    @can('updateMember', [$team, $member])
                                        <form method="POST" action="{{ route('teams.members.update', [$team, $member]) }}" class="flex items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <x-select name="role" aria-label="{{ __('Role of :name', ['name' => $member->name]) }}">
                                                @foreach ($role->assignableRoles() as $assignable)
                                                    <option value="{{ $assignable->value }}" @selected($member->membership->role === $assignable)>{{ $assignable->label() }}</option>
                                                @endforeach
                                            </x-select>
                                            <x-button variant="secondary" size="sm">{{ __('Save') }}</x-button>
                                        </form>
                                    @else
                                        {{ $member->membership->role->label() }}
                                    @endcan
                                </td>
                                <td class="px-4 py-3">
                                    @php($grant = $grants->get($member->id))
                                    @if ($grant?->isLimited())
                                        <span class="font-semibold">{{ trans_choice(':count site|:count sites', $grant->sites_count) }}, {{ trans_choice(':count server|:count servers', $grant->servers_count) }}</span>
                                    @else
                                        <span class="text-ink-2">{{ __('Everything') }}</span>
                                    @endif
                                    @if ($member->membership->role === \App\Enums\TeamRole::Viewer)
                                        @can('updateMember', [$team, $member])
                                            <a href="{{ route('teams.members.access.edit', [$team, $member]) }}" class="ml-1 text-[13px] text-ink-2 underline hover:text-ink">{{ __('Change') }}</a>
                                        @endcan
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex h-6 items-center rounded-full border border-rule px-2.5 text-[12px] font-semibold text-ink-2">
                                        {{ $member->hasEnabledTwoFactorAuthentication() ? __('On') : __('Off') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @can('removeMember', [$team, $member])
                                        @unless ($member->membership->role === \App\Enums\TeamRole::Owner && $ownerCount === 1)
                                            <form method="POST" action="{{ route('teams.members.destroy', [$team, $member]) }}" onsubmit="return confirm(@js($isYou ? __('Leave this team?') : __('Remove :name from the team?', ['name' => $member->name])))">
                                                @csrf
                                                @method('DELETE')
                                                <x-button variant="danger" size="sm">{{ $isYou ? __('Leave') : __('Remove') }}</x-button>
                                            </form>
                                        @endunless
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        @can('manageMembers', $team)
            <section class="grid gap-3">
                <header>
                    <h2 class="text-[15px] font-bold tracking-tight">{{ __('Pending invitations') }}</h2>
                    <p class="text-ink-2">{{ __('An invitation link works for :days days, once.', ['days' => \App\Models\TeamInvitation::VALID_DAYS]) }}</p>
                </header>

                @if ($team->invitations->isEmpty())
                    <p class="text-ink-2">{{ __('No invitation waiting.') }}</p>
                @else
                    <div class="overflow-hidden rounded-[10px] border border-rule bg-surface">
                        <ul class="divide-y divide-grid">
                            @foreach ($team->invitations as $invitation)
                                <li class="flex items-center justify-between gap-3 px-4 py-3">
                                    <div>
                                        <span class="font-mono text-[13px]">{{ $invitation->email }}</span>
                                        <span class="text-ink-3">· {{ $invitation->role->label() }}</span>
                                        @if ($invitation->limited)
                                            <span class="text-ink-3">· {{ trans_choice(':count site|:count sites', count($invitation->site_ids ?? [])) }}, {{ trans_choice(':count server|:count servers', count($invitation->server_ids ?? [])) }}</span>
                                        @endif
                                        <span @class(['text-ink-3', 'text-bad' => $invitation->expires_at->isPast()])>
                                            · {{ $invitation->expires_at->isPast() ? __('expired') : __('expires :when', ['when' => $invitation->expires_at->diffForHumans()]) }}
                                        </span>
                                    </div>
                                    <form method="POST" action="{{ route('teams.invitations.destroy', [$team, $invitation]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-button variant="secondary" size="sm">{{ __('Cancel') }}</x-button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <x-card :title="__('Invite someone')" :description="__('They get a mail with a link to create an account, or to join with the account they already have. A new invitation to the same address replaces the previous one.')">
                <form method="POST" action="{{ route('teams.invitations.store', $team) }}" class="grid gap-4">
                    @csrf
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <x-input :label="__('Email')" name="email" type="email" placeholder="name@example.com" required />
                        <x-select :label="__('Role')" name="role">
                            @foreach ($role->assignableRoles() as $assignable)
                                <option value="{{ $assignable->value }}" @selected(old('role', 'viewer') === $assignable->value)>{{ $assignable->label() }}</option>
                            @endforeach
                        </x-select>
                        <x-button>{{ __('Send invite') }}</x-button>
                    </div>

                    <details class="rounded-lg border border-rule px-3 py-2.5" @if (old('access') === 'limited') open @endif>
                        <summary class="cursor-pointer font-semibold">{{ __('What a viewer may see') }}</summary>
                        <p class="mt-1 mb-3 text-[13px] text-ink-2">{{ __('Only for a viewer: owners and admins always see everything.') }}</p>
                        <x-access-fields :team="$team" :sites="$sites" :servers="$servers" />
                    </details>
                </form>

                <x-slot:after>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-5 gap-y-1">
                        @foreach (\App\Enums\TeamRole::cases() as $assignable)
                            <dt class="font-bold">{{ $assignable->label() }}</dt>
                            <dd class="text-ink-2">{{ $assignable->description() }}</dd>
                        @endforeach
                    </dl>
                </x-slot:after>
            </x-card>
        @endcan

        @can('delete', $team)
            <x-card :title="__('Delete the team')" :description="__('The team, its sites, its memberships and its pending invitations are deleted for good.')">
                <form method="POST" action="{{ route('teams.destroy', $team) }}" onsubmit="return confirm(@js(__('Delete :team for good?', ['team' => $team->name])))">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">{{ __('Delete the team') }}</x-button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
