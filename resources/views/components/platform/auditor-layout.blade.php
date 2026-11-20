{{-- Read-only page frame for auditors: no platform admin menu, since auditors may only open the logs. --}}
<section class="application-settings-workspace w-full max-w-none">
    <header class="mb-5">
        <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Audit logs') }}</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('Read-only access to the activity, system and container logs of every team.') }}</p>
    </header>
    <div class="min-w-0">
        {{ $slot }}
    </div>
</section>
