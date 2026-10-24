<x-layouts.app :title="__('Create a team')" :lede="__('You will be the owner of the new team. Sites belong to exactly one team.')">
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('teams.store') }}" class="grid gap-3">
            @csrf
            <x-input :label="__('Team name')" name="name" required autofocus />
            <div><x-button>{{ __('Create the team') }}</x-button></div>
        </form>
    </x-card>
</x-layouts.app>
