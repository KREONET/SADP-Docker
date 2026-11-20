<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col gap-6">
            <x-server.provider-token-picker provider="hostinger" providerLabel="Hostinger"
                :tokens="$available_tokens" />
            <p class="text-[11px] text-neutral-500 dark:text-fg-faint">
                {{ __('New to Hostinger?') }}
                <a href="https://www.hostinger.com/vps/coolify-hosting?ref=coolify.io" target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-brand-light">{{ __('Create an account') }}</a>
                {{ __('through Coolify\'s affiliate link.') }}
            </p>
        </div>
    @elseif ($current_step === 2)
        <div wire:init="loadHostingerData">
            @if ($loading_data)
                <x-application.settings-section :title="__('Loading Hostinger')"
                    :description="__('Fetching data centers, plans, billing periods, and operating systems.')">
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading :text="__('Loading Hostinger data...')" />
                    </div>
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section :title="__('Unable to load Hostinger')"
                    :description="__('The selected token could not access the provider API.')">
                    <x-callout type="error" :title="__('Provider request failed')">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => 'hostinger']) }}"
                            {{ wireNavigate() }}>{{ __('Select another token') }}</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $dataCenterOptions = collect($data_centers)->map(fn ($dataCenter) => [
                        'value' => $dataCenter['id'],
                        'label' => ($dataCenter['city'] ?? $dataCenter['name'] ?? $dataCenter['id'])
                            . (!empty($dataCenter['location']) ? ' · ' . strtoupper($dataCenter['location']) : ''),
                    ])->values()->all();
                    $priceOptions = collect($this->priceOptions)->map(fn ($price) => [
                        'value' => $price['id'],
                        'label' => $this->priceLabel($price),
                    ])->values()->all();
                    $templateOption = fn ($template) => [
                        'value' => $template['id'],
                        'label' => $template['name'] ?? $template['description'] ?? $template['id'],
                    ];
                    $osTemplateOptions = collect($this->osTemplates)->map($templateOption)->values()->all();
                    $appTemplateOptions = collect($this->appTemplates)->map($templateOption)->values()->all();
                    $privateKeyOptions = $private_keys->map(fn ($key) => [
                        'value' => $key->id,
                        'label' => $key->name,
                    ])->values()->all();
                    $postInstallScriptOptions = collect($post_install_scripts)->map(fn ($script) => [
                        'value' => $script['id'],
                        'label' => $script['name'],
                    ])->values()->all();
                @endphp

                <form wire:submit="submit" class="flex flex-col gap-6">
                    <x-application.settings-section :title="__('Hostinger server')"
                        :description="__('Choose the data center, plan, operating system, and Coolify SSH key.')">
                        <x-slot:actions>
                            <button type="submit" class="button button-highlighted"
                                wire:loading.attr="disabled" wire:target="submit"
                                @disabled(!$private_key_id)>
                                {{ __('Buy and create') }}
                                <x-loading-on-button wire:loading wire:target="submit" />
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <x-forms.input id="server_name" :label="__('Server name')"
                                    :helper="__('A friendly name shown in Coolify and used as the VPS hostname.')" />
                            </div>
                            <x-forms.listbox id="selected_data_center_id" :label="__('Data center')" required live
                                :placeholder="__('Select a data center')" :options="$dataCenterOptions" />
                            <x-forms.listbox id="selected_price_id" :label="__('Plan and billing period')" required live
                                :disabled="!$selected_data_center_id" :placeholder="__('Select a plan')"
                                :options="$priceOptions" />
                            <x-forms.listbox id="selected_os_template_id" :label="__('Operating system')" required live
                                :disabled="!$selected_price_id" :placeholder="__('Select an operating system')"
                                :options="$osTemplateOptions" />
                            <x-forms.listbox id="selected_app_template_id" :label="__('Operating system with application')"
                                live :disabled="!$selected_price_id" :placeholder="__('Or select an operating system with an application')"
                                :options="$appTemplateOptions"
                                :helper="__('Replaces the operating system selected above. Applications and panels, such as another Coolify or CyberPanel, can conflict with the Coolify proxy on ports 80 and 443.')" />
                            @if ($private_keys->isEmpty())
                                <div>
                                    <label class="mb-1.5 flex w-fit items-center gap-1.5">{{ __('Private key') }}
                                        <x-highlighted text="*" />
                                    </label>
                                    <div
                                        class="flex min-h-8 items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2">
                                        <span class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ __('A private key is required.') }}</span>
                                        <x-modal-input :title="__('New Private Key')">
                                            <x-slot:content>
                                                <button type="button" class="button">{{ __('Create key') }}</button>
                                            </x-slot:content>
                                            <livewire:security.private-key.create :modal_mode="true" from="server" />
                                        </x-modal-input>
                                    </div>
                                </div>
                            @else
                                <x-forms.listbox id="private_key_id" :label="__('Private key')" required
                                    :placeholder="__('Select a private key')" :options="$privateKeyOptions"
                                    :helper="__('This key is added to the Hostinger VPS automatically.')" />
                            @endif
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section :title="__('Advanced options')"
                        :description="__('Provider backups, account SSH keys, and post-install automation.')">
                        <div class="flex flex-col gap-4">
                            <div class="-mx-2.5 grid gap-3 lg:grid-cols-2">
                                <x-forms.checkbox id="enable_backups" :label="__('Enable weekly Hostinger backups (extra cost)')" fullWidth
                                    :helper="__('Hostinger charges extra for backups. Check the price in hPanel before you enable them.')" />
                            </div>
                            <x-forms.listbox :label="__('Extra SSH keys')" id="selected_public_key_ids"
                                :helper="__('Existing keys from the Hostinger account.')" multiple searchable
                                :disabled="count($hostinger_public_keys) === 0" searchPlaceholder="Search SSH keys…"
                                :placeholder="count($hostinger_public_keys) ? 'Select SSH keys' : 'No account keys found'"
                                :options="collect($hostinger_public_keys)->map(fn ($publicKey) => ['value' => $publicKey['id'], 'label' => $publicKey['name']])->values()->all()" />
                            @if ($post_install_scripts)
                                <x-forms.listbox id="selected_post_install_script_id"
                                    :label="__('Hostinger post-install script')"
                                    :placeholder="__('No post-install script')" :options="$postInstallScriptOptions"
                                    :helper="__('Hostinger runs this as /post_install after setup and writes output to /post_install.log.')" />
                            @endif
                            <x-callout type="warning" :title="__('This purchase is billed by Hostinger')">
                                {{ __('The VPS is purchased with your Hostinger account\'s default payment method. Review the selected plan and billing period before continuing.') }}
                            </x-callout>
                        </div>
                    </x-application.settings-section>
                    @include('livewire.server.new.partials.image-scanning')
                </form>
            @endif
        </div>
    @endif
</div>
