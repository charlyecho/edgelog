@php
    use App\Ingest\ShipperSetup;
@endphp

<ol class="grid gap-4">
    <li class="grid gap-1.5">
        <p><span class="font-semibold">1.</span> {{ __('The shipper and the site token must be on the server first: follow Send the log in the site settings. Then take the new shipper, which knows --errors:') }}</p>
        <x-code :code="$setup->installCommands()" />
    </li>
    <li class="grid gap-1.5">
        <p><span class="font-semibold">2.</span> {{ __('On a Laravel Forge server, the apps\' logs belong to the forge user: let the shipper read them.') }}</p>
        <x-code :code="ShipperSetup::errorsReadAccessCommand()" />
    </li>
    <li class="grid gap-1.5">
        <p><span class="font-semibold">3.</span> {{ __('Then one service per log. Put the log\'s real path in place of the example; a * follows the newest file of an app that writes one a day. The times of Laravel, CodeIgniter and Craft logs are read as UTC.') }}</p>
        <div class="grid gap-2">
            @foreach ($kinds as $kind)
                <details class="rounded-[9px] border border-rule">
                    <summary class="cursor-pointer px-3 py-2 font-semibold">{{ $kind->label() }} <span class="font-mono text-xs font-normal text-ink-3">{{ $kind->examplePath() }}</span></summary>
                    <div class="border-t border-rule p-3">
                        <x-code :code="$setup->errorsServiceCommands($kind)" />
                    </div>
                </details>
            @endforeach
        </div>
    </li>
    <li class="grid gap-1.5">
        <p><span class="font-semibold">4.</span> {{ __('Laravel, optional: laravel.log does not say which page failed. With this tap it writes the request after each entry (its URL, address, method, host and referrer), so no guess from the access log is needed.') }}</p>
        <x-code :code="ShipperSetup::laravelRequestTap()" />
    </li>
</ol>
<p class="mt-3 text-ink-2">{{ __('CodeIgniter writes the page that failed; for the others, the request is looked for in the access log at the same second: one answered with an error, or with the status the log names. The referrer, the browser and the address always come from there.') }}</p>
