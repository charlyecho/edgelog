<x-layouts.guest :title="__('Team invitation')">
    @if ($invitation === null)
        <p class="text-ink-2">{{ __('This invitation link is not valid. It may have expired, been cancelled, or already been used. Ask the person who invited you to send a new one.') }}</p>
    @else
        <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-rule bg-page p-3">
            <x-tile :label="$invitation->team->name" :seed="$invitation->team->uuid" size="size-9 text-lg" />
            <div class="min-w-0">
                <p class="truncate font-bold">{{ $invitation->team->name }}</p>
                <p class="text-[13px] text-ink-3">{{ $invitation->role->label() }}</p>
            </div>
        </div>

        <p class="text-ink-2">
            {{ __(':inviter invited :email to join :team as :role.', [
                'inviter' => $invitation->inviter?->name ?? __('Someone'),
                'email' => $invitation->email,
                'team' => $invitation->team->name,
                'role' => $invitation->role->label(),
            ]) }}
        </p>

        @guest
            <p class="mt-3 text-ink-2">{{ __('Sign in with this email address to accept, or create an account if you do not have one yet.') }}</p>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <x-button :href="route('login')">{{ __('Sign in') }}</x-button>
                <x-button variant="secondary" :href="route('register', ['email' => $invitation->email])">{{ __('Create an account') }}</x-button>
            </div>
        @else
            @if ($emailMatches)
                <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-4">
                    @csrf
                    <x-button class="w-full">{{ __('Join :team', ['team' => $invitation->team->name]) }}</x-button>
                </form>
            @else
                <p class="mt-3 text-bad">{{ __('You are signed in as :current. Sign out and sign in with :email to accept.', ['current' => auth()->user()->email, 'email' => $invitation->email]) }}</p>
            @endif
        @endguest
    @endif
</x-layouts.guest>
