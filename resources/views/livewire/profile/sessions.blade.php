<section class="application-settings-section">
    <div class="application-settings-section-header">
        <div>
            <h2>{{ __('Active sessions') }}</h2>
            <p>{{ __('Browsers and devices that are signed in to your account.') }}</p>
        </div>
    </div>
    <div class="application-settings-section-body space-y-4">
        @if (! $isSupported)
            <x-callout type="warning" :title="__('Session list unavailable')">
                {{ __('Active sessions can only be managed with the database session driver.') }}
            </x-callout>
        @else
            <div class="overflow-x-auto rounded-[10px] border border-neutral-200 dark:border-white/[0.07]">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('IP address') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Browser') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Last active') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
                        @foreach ($sessions as $session)
                            <tr
                                class="text-[13px] text-neutral-600 dark:text-fg-dim">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ $session['ip_address'] ?? __('Unknown') }}
                                    @if ($session['is_current'])
                                        <x-status-badge class="ml-2" :status="__('This device')" type="success" />
                                    @endif
                                </td>
                                <td class="px-3 py-2 break-all">{{ $session['user_agent'] ?: __('Unknown') }}</td>
                                <td class="px-3 py-2 whitespace-nowrap"
                                    title="{{ $session['last_active_at']->toIso8601String() }}">
                                    {{ $session['last_active_at']->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form wire:submit="logoutOtherSessions" class="grid gap-4 sm:grid-cols-2 sm:items-end">
                @if ($requiresPassword)
                    <x-forms.input id="password" :label="__('Current password')" required type="password"
                        autocomplete="current-password" />
                @endif
                <div class="flex justify-end sm:col-start-2">
                    <x-forms.button type="submit" isError>
                        {{ __('Log out other sessions') }}
                    </x-forms.button>
                </div>
            </form>
        @endif
    </div>
</section>
