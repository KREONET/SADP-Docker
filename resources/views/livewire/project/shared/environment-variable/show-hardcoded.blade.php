<div class="env-table-item"
    x-show="typeof envFilter === 'undefined' || envFilter === 'all' || envFilter === '{{ $isPreview ? 'preview' : 'production' }}'">
    <div class="data-table-row env-table-grid {{ $showEnvironmentType ? '' : 'env-table-grid-no-type' }}">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" data-env-name-trigger
                class="env-key-label min-w-0 truncate text-left font-mono text-[13px] text-black dark:text-fg"
                title="{{ $key }}"
                @click="$el.closest('.data-table-row').querySelector('[data-env-settings-trigger]').click()">
                {{ $key }}
            </button>
            @if (filled($comment))
                <x-helper :helper="e($comment)" />
            @endif
            @if ($serviceName)
                <span class="table-badge shrink-0">{{ $serviceName }}</span>
            @endif
        </div>
        <span class="env-managed-desktop data-table-cell-check">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
        </span>
        @if ($showEnvironmentType)
            <div class="env-type-desktop text-[13px] text-neutral-500 dark:text-fg-dim">{{ $isPreview ? __('Preview') : __('Production') }}</div>
        @endif
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <div class="flex items-center gap-0.5 justify-self-end">
            @unless (auth()->user()?->isMember() ?? true)
                {{-- Copying needs a recent re-authentication; without one, open the details to confirm. --}}
                <button type="button" title="{{ __('Copy value') }}" aria-label="{{ __('Copy value') }}"
                    class="icon-button group shrink-0" data-env-copy-value x-data="copyButton"
                    @click="const copiedValue = await $wire.copyValue();
                        if (copiedValue !== null && copiedValue !== undefined) {
                            copy(copiedValue);
                        } else if ($wire.sensitiveConfirmationRequired) {
                            $el.closest('.data-table-row').querySelector('[data-env-settings-trigger]').click();
                        } else {
                            copy(null);
                        }">
                    <span class="inline-flex transition-transform duration-150 ease-out group-active:scale-75">
                        <x-reicon name="copy" x-show="!copied" class="size-3.5" />
                        <x-reicon name="check" x-cloak x-show="copied" class="size-3.5 text-success"
                            x-transition:enter="transition-transform duration-200 ease-out"
                            x-transition:enter-start="scale-50" x-transition:enter-end="scale-100" />
                    </span>
                </button>
            @endunless
            <x-modal-input :title="__('Environment variable details')" :closeOutside="false" :wireIgnore="false"
                wireOpen="detailsOpen">
                <x-slot:content>
                    <button type="button" data-env-settings-trigger class="icon-button shrink-0"
                        title="{{ __('View environment variable') }}" aria-label="{{ __('View environment variable') }}">
                        <x-reicon name="settings" class="size-3.5" />
                    </button>
                </x-slot:content>
                <div class="flex w-full flex-col gap-4">
                    <x-forms.input :label="__('Name')" :value="$key" readonly />
                    <x-forms.input :label="__('Value')" :value="$value ?? ''" readonly />
                    @if ($sensitiveConfirmationRequired)
                        <x-sensitive-confirmation action="confirmSensitiveAccess" wire:key="env-hardcoded-copy-confirm"
                            :message="__('Confirm your identity to view or copy this value. You will not be asked again for :minutes minutes.', ['minutes' => \App\Support\Reauthentication::windowMinutes()])" />
                    @endif
                    @if (filled($comment))
                        <x-forms.input :label="__('Comment')" :value="$comment" readonly />
                    @endif
                    <x-callout type="info" :title="__('Managed by Docker Compose')">
                        {{ __('Update this value in the Compose file.') }}
                    </x-callout>
                </div>
            </x-modal-input>
        </div>
    </div>
</div>
