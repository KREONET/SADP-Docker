<div class="application-settings-form w-full">
    <x-application.settings-section id="server-terminal-access-section" :title="__('Terminal access')"
        :helper="__('Control dashboard terminal access for this server and its containers.')">
        <x-slot:badges>
            <x-status-badge :status="$isTerminalEnabled ? __('Enabled') : __('Disabled')" :type="$isTerminalEnabled ? 'success' : 'neutral'" />
        </x-slot:badges>
        <x-slot:actions>
            @can('update', $server)
                <div wire:key="terminal-access-change-{{ $isTerminalEnabled }}">
                    <x-modal-confirmation :title="__('Confirm Terminal Access Change?')"
                        temporaryDisableTwoStepConfirmation
                        buttonTitle="{{ $isTerminalEnabled ? __('Disable terminal') : __('Enable terminal') }}"
                        submitAction="toggleTerminal" :actions="[
                            $isTerminalEnabled
                                ? __('Disable terminal access for this server and all of its containers.')
                                : __('Enable terminal access for this server and all of its containers.'),
                            __('The change takes effect immediately for every user.'),
                        ]" confirmationText="{{ $server->name }}"
                        :shortConfirmationLabel="__('Server Name')"
                        step3ButtonText="{{ $isTerminalEnabled ? __('Disable Terminal') : __('Enable Terminal') }}"
                        :isHighlightedButton="!$isTerminalEnabled" />
                </div>
            @endcan
        </x-slot:actions>

        <div class="flex items-start gap-3">
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                <x-reicon name="browser-terminal" class="size-4" />
            </div>
            <div>
                <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                    {{ $isTerminalEnabled ? __('Dashboard terminal is available') : __('Dashboard terminal is blocked') }}
                </p>
                <p class="mt-1 max-w-xl text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('This setting applies to every user, including administrators and the team owner. Only administrators and owners can change it.') }}
                </p>
            </div>
        </div>
    </x-application.settings-section>
</div>
