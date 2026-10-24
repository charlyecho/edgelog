@props(['href'])

<li class="flex min-w-0 items-center gap-1.5 font-semibold text-ink-3">
    <a href="{{ $href }}" class="truncate no-underline hover:text-ink">{{ $slot }}</a>
    <span class="text-gap" aria-hidden="true">›</span>
</li>
