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
                <form method="POST" action="{{ route('teams.invitations.store', $team) }}" class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    <x-input :label="__('Email')" name="email" type="email" placeholder="name@example.com" required />
                    <x-select :label="__('Role')" name="role">
                        @foreach ($role->assignableRoles() as $assignable)
                            <option value="{{ $assignable->value }}" @selected(old('role', 'viewer') === $assignable->value)>{{ $assignable->label() }}</option>
                        @endforeach
                    </x-select>
                    <x-button>{{ __('Send invite') }}</x-button>
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
