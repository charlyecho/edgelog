<x-mail::message>
# {{ __('Join :team', ['team' => $invitation->team->name]) }}

{{ __(':inviter invited you to join :team on :app as :role.', [
    'inviter' => $invitation->inviter?->name ?? __('Someone'),
    'team' => $invitation->team->name,
    'app' => config('app.name'),
    'role' => $invitation->role->label(),
]) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept the invitation') }}
</x-mail::button>

{{ __('The link works once and expires on :date. If you do not have an account yet, you can create one with this email address.', ['date' => $invitation->expires_at->translatedFormat('l j F Y')]) }}

{{ __('If you did not expect this invitation, you can ignore this email.') }}
</x-mail::message>
