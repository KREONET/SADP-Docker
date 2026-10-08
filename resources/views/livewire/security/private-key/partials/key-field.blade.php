{{--
    Private key field. The stored key is only sent to the browser after
    re-authentication (showPrivateKey); an empty editor keeps the stored key.
    Expects the parent form to provide x-data="{ showPrivateKey: false }".
--}}
<div class="mb-1.5 flex items-center justify-between gap-3">
    <label class="text-[13px] font-medium">{{ __('Private key') }} <span class="text-helper">*</span></label>
    @can('update', $private_key)
        <div class="flex items-center gap-3">
            @if (! $privateKeyRevealed)
                <button type="button" data-private-key-reveal
                    class="text-[11px] font-medium text-coollabs hover:underline dark:text-warning"
                    wire:click="showPrivateKey">{{ __('Show private key') }}</button>
            @endif
            <button type="button" class="text-[11px] font-medium text-coollabs hover:underline dark:text-warning"
                x-on:click="showPrivateKey = !showPrivateKey"
                x-text="showPrivateKey ? @js(__('Hide editor')) : @js($privateKeyRevealed ? __('Edit key') : __('Replace key'))"></button>
        </div>
    @endcan
</div>
@if ($sensitiveConfirmationRequired && ! $privateKeyRevealed)
    <x-sensitive-confirmation action="showPrivateKey" class="mb-2"
        :message="__('Confirm your identity to view this private key. You will not be asked again for :minutes minutes.', ['minutes' => \App\Support\Reauthentication::windowMinutes()])" />
@endif
<div x-show="!showPrivateKey">
    @if ($privateKeyRevealed)
        <x-forms.input canGate="update" :canResource="$private_key" allowToPeak="false"
            type="password" id="privateKeyValue" required disabled />
    @else
        <input type="text" disabled class="input w-full italic !text-neutral-500 dark:!text-neutral-500"
            value="{{ __('Hidden. Use Show private key to view it.') }}" />
    @endif
</div>
<div x-cloak x-show="showPrivateKey">
    <x-forms.textarea canGate="update" :canResource="$private_key" rows="{{ $rows ?? 10 }}"
        id="privateKeyValue" :required="$privateKeyRevealed" monospace
        :placeholder="$privateKeyRevealed ? null : __('Paste a new private key to replace the stored one')" />
</div>
