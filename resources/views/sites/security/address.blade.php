<x-section-frame section="security" :team="$team" :site="$site" current="ip" :title="__('An IP address')" :choice="$choice">
    <section class="max-w-4xl rounded-[10px] border border-rule bg-surface p-4 shadow-card">
        @include('sites.security.address-panel')
    </section>
</x-section-frame>
