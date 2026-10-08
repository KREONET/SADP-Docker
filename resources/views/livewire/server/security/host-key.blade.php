<div>
    <x-application.settings-section id="server-ssh-host-key-section" :title="__('SSH host key')"
        :helper="__('Coolify trusts the host key a server presents on the first connection and refuses connections when it changes.')">
        <x-slot:actions>
            <x-status-badge :status="count($fingerprints) > 0 ? __('Trusted') : __('Not trusted yet')"
                :type="count($fingerprints) > 0 ? 'success' : 'warning'" />
        </x-slot:actions>

        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                    <x-reicon name="key" class="size-4" />
                </div>
                <div class="min-w-0">
                    @forelse ($fingerprints as $fingerprint)
                        <p wire:key="ssh-host-key-{{ $fingerprint }}"
                            class="break-all font-mono text-sm text-neutral-950 dark:text-fg">{{ $fingerprint }}</p>
                    @empty
                        <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                            {{ __('No host key has been recorded for this server yet.') }}
                        </p>
                    @endforelse
                    <p class="mt-1 max-w-xl text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                        @if ($trustedAt)
                            {{ __('Trusted since :date.', ['date' => $trustedAt]) }}
                        @endif
                        {{ __('If the host key changed on purpose (for example after reinstalling the server), compare the new fingerprint on the server with ssh-keygen -lf before resetting the trust.') }}
                    </p>
                </div>
            </div>

            @can('update', $server)
                <div wire:key="ssh-host-key-reset">
                    <x-modal-confirmation :title="__('Reset SSH host key trust?')" temporaryDisableTwoStepConfirmation
                        :buttonTitle="__('Reset trust')" submitAction="resetTrust" :actions="[
                            __('The stored host key fingerprint and the known_hosts file of this server are deleted.'),
                            __('The next connection trusts whatever host key the server presents.'),
                        ]" confirmationText="{{ $server->name }}"
                        :shortConfirmationLabel="__('Server Name')" :step3ButtonText="__('Reset trust')"
                        isErrorButton />
                </div>
            @endcan
        </div>
    </x-application.settings-section>
</div>
