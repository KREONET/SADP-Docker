@php
    // Each item shows only to users who pass the gate of its route.
    $platformMenuItems = array_filter([
        ['label' => 'Overview', 'route' => 'platform.dashboard', 'icon' => 'grid', 'gate' => 'operatePlatform'],
        ['label' => 'Applications', 'route' => 'platform.applications', 'icon' => 'layers', 'gate' => 'operatePlatform'],
        ['label' => 'Groups', 'route' => 'platform.groups', 'icon' => 'teams', 'gate' => 'operatePlatform'],
        ['label' => 'Users', 'route' => 'platform.users', 'icon' => 'profile', 'gate' => 'operatePlatform'],
        ['label' => 'Sources', 'route' => 'platform.sources', 'icon' => 'globe', 'gate' => 'accessPlatformAdmin'],
        ['label' => 'Security', 'route' => 'platform.security', 'icon' => 'keys', 'gate' => 'accessPlatformAdmin'],
        ['label' => 'Logs', 'route' => 'platform.logs', 'icon' => 'file-content', 'gate' => 'viewAuditLogs'],
    ], fn (array $menuItem): bool => \Illuminate\Support\Facades\Gate::allows($menuItem['gate']));
@endphp

<section class="application-settings-workspace w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">{{ __('Platform Admin') }}</h1>
        <p class="settings-mobile-description">{{ __('Manage every team, application, source, security policy and log.') }}</p>
    </header>
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <aside class="application-settings-navigation min-w-0 xl:self-start">
            <nav aria-label="{{ __('Platform admin') }}"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-7 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                <div class="nav-section col-span-full hidden xl:block">{{ __('Platform Admin') }}</div>
                @foreach ($platformMenuItems as $menuItem)
                    <a wire:key="platform-menu-{{ str($menuItem['label'])->slug() }}"
                        @class(['menu-item', 'menu-item-active' => request()->routeIs($menuItem['route'])])
                        {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ __($menuItem['label']) }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</section>
