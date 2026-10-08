<div>
    <x-slot:title>
        {{ __('Authentication') }} | {{ brand_name() }}
    </x-slot>

    <x-settings.layout>
        <x-slot:submenu>
            <div
                x-data="{ activeProvider: location.hash.slice(1).replace('-oauth-section', '') || @js($selectedProvider ?? array_key_first($oauth_settings_map)) }"
                @hashchange.window="activeProvider = location.hash.slice(1).replace('-oauth-section', '')">
                <nav aria-label="{{ __('OAuth providers') }}" class="grid gap-0.5 py-1">
                    @foreach ($oauth_settings_map as $provider => $oauth_setting)
                        <a href="#{{ $provider }}-oauth-section" class="menu-item min-h-8! py-1! text-[12px]!"
                            :class="{ 'menu-item-active': activeProvider === '{{ $provider }}' }"
                            @click.prevent="activeProvider = '{{ $provider }}'; history.replaceState(null, '', '#{{ $provider }}-oauth-section'); window.scrollToSettingsSection?.('{{ $provider }}-oauth-section')">
                            <span class="menu-item-icon bg-current"
                                style="mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat; -webkit-mask: url('{{ asset('svgs/' . $provider . '.svg') }}') center / contain no-repeat;"></span>
                            <span class="menu-item-label">{{ $oauth_setting['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        </x-slot:submenu>

        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section :title="__('Registration')"
                :description="__('Control password registration when an OAuth provider is available.')">
                <x-forms.checkbox canGate="update" :canResource="$settings"
                    id="disable_registration_when_oauth_enabled"
                    :label="__('Disable password registration when OAuth is enabled')"
                    :helper="__('OAuth providers can still create users when registration is enabled for that provider.')"
                    instantSave="saveRegistrationPolicy" />
            </x-application.settings-section>

            @foreach ($oauth_settings_map as $provider => $oauth_setting)
                <x-application.settings-section id="{{ $provider }}-oauth-section" class="scroll-mt-28"
                    title="{{ $oauth_setting['label'] }}">
                    <x-slot:actions>
                        <div x-data="{ enabled: @js((bool) $oauth_setting['enabled']), provider: @js($provider) }">
                            <x-forms.button canGate="update" :canResource="$settings" type="button"
                                :isHighlighted="!$oauth_setting['enabled']"
                                x-on:click="
                                    if (!enabled) {
                                        const invalidField = [...$el.closest('section').querySelectorAll('[required]')]
                                            .find(field => !field.checkValidity());
                                        if (invalidField) { invalidField.reportValidity(); return; }
                                    }
                                    $wire.toggleProvider(provider);
                                ">
                                {{ $oauth_setting['enabled'] ? __('Disable') : __('Enable') }}
                            </x-forms.button>
                        </div>
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        @if ($provider === 'oidc')
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.redirect_uri"
                                placeholder="{{ route('auth.callback', $provider) }}" :label="__('Redirect URI')" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.base_url" :label="__('Issuer URL')" required
                                :helper="__('OpenID Provider issuer URL, for example https://example.okta.com. Coolify uses it to discover the authorization, token, userinfo, and JWKS endpoints.')" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_id" :label="__('Client ID')" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_secret" type="password"
                                :label="__('Client secret')" autocomplete="new-password" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.scopes" :label="__('Scopes')"
                                :helper="__('Must include openid. Common scopes are openid email profile groups.')" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.clock_skew_seconds" type="number"
                                :label="__('Clock skew (seconds)')" />
                            <div class="lg:col-span-2">
                                <x-forms.input canGate="update" :canResource="$settings"
                                    id="oauth_settings_map.{{ $provider }}.custom_label" :label="__('Login button label')"
                                    :placeholder="__('Login with SSO')" />
                            </div>
                        @else
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.redirect_uri"
                                placeholder="{{ route('auth.callback', $provider) }}" :label="__('Redirect URI')" />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_id" :label="__('Client ID')" required />
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.client_secret" type="password"
                                :label="__('Client secret')" autocomplete="new-password" required />
                        @endif

                        @if ($provider === 'authentik')
                            <x-forms.input canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.base_url" :label="__('Base URL')" required />
                        @endif

                    </div>

                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                        @if ($provider === 'oidc')
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.allow_registration"
                                :label="__('Allow OIDC user creation')"
                                :helper="__('Allow a successful OIDC login to create a user when password registration is disabled.')" />
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.require_email_verified"
                                :label="__('Require verified email')" />
                            <x-forms.checkbox canGate="update" :canResource="$settings"
                                id="oauth_settings_map.{{ $provider }}.use_pkce" :label="__('Use PKCE')" />
                        @endif
                        <x-forms.checkbox canGate="update" :canResource="$settings"
                            id="oauth_settings_map.{{ $provider }}.auto_join_root_team"
                            :label="__('Auto-join new users to Root team')"
                            :helper="__('Add newly-created OAuth users to the Root team as members without creating a personal team.')" />
                    </div>

                    @if ($provider === 'oidc')
                        <div class="mt-6 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-black dark:text-fg">{{ __('SCIM provisioning') }}</h3>
                                    <p class="mt-1 max-w-2xl text-[13px] text-neutral-600 dark:text-fg-dim">
                                        {{ __('Let the identity provider create and remove users. When the provider deletes or deactivates a user, the account is permanently deleted here, together with teams where that user is the only member.') }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <x-forms.button canGate="update" :canResource="$settings" type="button"
                                        wire:click="generateScimToken">
                                        {{ $hasScimToken ? __('Regenerate SCIM token') : __('Generate SCIM token') }}
                                    </x-forms.button>
                                    @if ($hasScimToken)
                                        <x-forms.button canGate="update" :canResource="$settings" type="button" isError
                                            wire:click="revokeScimToken">
                                            {{ __('Revoke') }}
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <x-forms.copy-input :label="__('SCIM base URL')" :text="url('/api/scim/v2')" />
                                @if ($newScimToken)
                                    <div>
                                        <x-forms.copy-input :label="__('SCIM token')" :text="$newScimToken" />
                                        <p class="mt-1 text-xs text-amber-600 dark:text-warning">{{ __('Copy this token now. It will not be shown again.') }}</p>
                                    </div>
                                @elseif ($hasScimToken)
                                    <p class="self-end text-[13px] text-neutral-600 dark:text-fg-dim">{{ __('A SCIM token is active.') }}</p>
                                @else
                                    <p class="self-end text-[13px] text-neutral-600 dark:text-fg-dim">{{ __('SCIM provisioning is off until a token is generated.') }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>
            @endforeach
        </form>
    </x-settings.layout>
</div>
