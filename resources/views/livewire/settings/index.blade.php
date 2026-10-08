<div>
    <x-slot:title>
        {{ __('Settings') }} | {{ brand_name() }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            {{-- instance_timezone auto-saves via $wire.set + submit; exclude it so
                 the bar does not flash while the snapshot catches up. --}}
            <x-unsaved-bar action="submit"
                targets="fqdn,instance_name,public_ipv4,public_ipv6,dev_helper_version" />
            <x-application.settings-section :title="__('General')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <div @class([
                        'lg:col-span-2' => !str_starts_with(strtolower($fqdn ?? ''), 'https://'),
                    ])>
                        <x-forms.input canGate="update" :canResource="$settings" id="fqdn" :label="__('URL')"
                            :helper="__('Enter the full URL of the instance (for example, https://dashboard.example.com).<br><br><span class=\'text-coollabs dark:text-warning\'>Important:</span> Include <b>https://</b> to secure the dashboard with HTTPS.')"
                            placeholder="https://coolify.yourdomain.com" />
                    </div>

                    @if (str_starts_with(strtolower($fqdn ?? ''), 'https://'))
                        <div>
                            <x-forms.listbox canGate="update" :canResource="$settings"
                                id="is_dashboard_force_https_enabled" :label="__('Redirect HTTP to HTTPS')"
                                onChange="submit"
                                :helper="__('Disable only when Cloudflare Tunnel or another proxy connects to Coolify over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL.')"
                                :options="[
                                    ['value' => true, 'label' => __('Enabled')],
                                    ['value' => false, 'label' => __('Disabled')],
                                ]" />
                        </div>
                    @endif

                    <x-forms.input canGate="update" :canResource="$settings" id="instance_name" :label="__('Name')"
                        placeholder="Coolify" :helper="__('Custom name for this Coolify instance.')" />

                    <x-forms.listbox canGate="update" :canResource="$settings" id="default_locale"
                        :label="__('Default language')" onChange="updateDefaultLocale"
                        :helper="__('Language for users who have not chosen one in their profile.')"
                        :options="collect(\App\Support\InterfaceLocale::available())->map(fn ($name, $code) => ['value' => $code, 'label' => $name])->values()->all()" />

                    {{-- Use searchable-listbox so the label row (h-4) and control height match
                         sibling x-forms.input fields (Name). onChange auto-saves like before. --}}
                    <x-forms.searchable-listbox id="instance_timezone" :label="__('Instance timezone')"
                        :helper="__('Timezone used for update checks and the automatic update schedule.')"
                        :searchPlaceholder="__('Search timezones')" :emptyText="__('No matching timezone')"
                        onChange="submit" :options="collect($this->timezones)->map(fn ($timezone) => [
                            'value' => $timezone,
                            'label' => $timezone,
                        ])->all()" :disabled="! auth()->user()->can('update', $settings)" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section :title="__('Network addresses')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv4" type="password"
                        :label="__('Instance public IPv4')"
                        :helper="__('Set this when Coolify cannot detect the correct public IPv4 address.')"
                        placeholder="1.2.3.4" autocomplete="new-password" />
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv6" type="password"
                        :label="__('Instance public IPv6')"
                        :helper="__('Set this when Coolify cannot detect the correct public IPv6 address.')"
                        placeholder="2001:db8::1" autocomplete="new-password" />
                </div>
            </x-application.settings-section>

            @if (isDev())
                <x-application.settings-section :title="__('Development helper')">
                    <x-forms.input canGate="update" :canResource="$settings" id="dev_helper_version"
                        :label="__('Version override')"
                        :helper="__('Override the default coolify-helper image version. Leave empty to use :version.', ['version' => config('constants.coolify.helper_version')])"
                        placeholder="{{ config('constants.coolify.helper_version') }}" />
                </x-application.settings-section>
            @endif
        </form>

        <form wire:submit="saveBranding" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('Branding')"
                :helper="__('Site name and logo shown in page titles, the navigation bar and the sign-in pages.')">
                <x-slot:actions>
                    <x-forms.button type="submit" canGate="update" :canResource="$settings">
                        {{ __('Save') }}
                    </x-forms.button>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$settings" id="brand_name" :label="__('Site name')"
                        maxlength="60" placeholder="PADED"
                        :helper="__('Leave empty to use the default name (PADED).')" />

                    <div class="flex min-w-0 flex-col gap-2" x-data>
                        <span class="text-sm font-medium">{{ __('Logo') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-neutral-200 bg-white p-1.5 dark:border-white/10 dark:bg-white/[0.06]">
                                <img src="{{ brand_logo_url() ?? asset('coolify-logo.svg') }}" alt="{{ __('Current logo') }}"
                                    class="max-h-full max-w-full object-contain">
                            </div>
                            @can('update', $settings)
                                <input x-ref="logoInput" type="file" wire:model="logo" class="hidden"
                                    accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                    x-on:livewire-upload-finish="$wire.uploadLogo()">
                                <x-forms.button type="button" x-on:click="$refs.logoInput.click()"
                                    wire:loading.attr="disabled" wire:target="logo,uploadLogo">
                                    <span wire:loading.remove wire:target="logo,uploadLogo">{{ __('Upload logo') }}</span>
                                    <span wire:loading wire:target="logo,uploadLogo">{{ __('Uploading…') }}</span>
                                </x-forms.button>
                                @if ($settings->brand_logo_path)
                                    <x-forms.button type="button" wire:click="removeLogo" isError>
                                        {{ __('Remove') }}
                                    </x-forms.button>
                                @endif
                            @endcan
                        </div>
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ __('PNG, JPG, WebP or SVG, up to 1 MB. A square image works best.') }}
                        </p>
                        @error('logo')
                            <p class="text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit="saveInternalNetworks" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('Internal network')"
                :helper="__('Clients from these ranges can open applications whose exposure is set to internal network only.')">
                <x-slot:actions>
                    <x-forms.button type="submit" canGate="update" :canResource="$settings">
                        {{ __('Save') }}
                    </x-forms.button>
                </x-slot:actions>
                <x-forms.textarea canGate="update" :canResource="$settings" id="internal_network_cidrs"
                    :label="__('Allowed IP addresses and ranges')" rows="4"
                    placeholder="10.0.0.0/8&#10;192.168.0.0/16&#10;2001:db8::/32"
                    :helper="__('One IP address or CIDR range per line (commas also work). When the list is empty, nobody can open internal network only applications.')" />
            </x-application.settings-section>
        </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>{{ __('The Coolify instance domain will conflict with existing resources.') }}</li>
                <li>{{ __('SSL certificates might not work correctly.') }}</li>
                <li>{{ __('Routing behavior will be unpredictable.') }}</li>
                <li>{{ __('You may not be able to access the Coolify dashboard properly.') }}</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>
    </x-settings.layout>
</div>
