{{--
    Inline re-authentication form for revealing secrets.
    Calls the given Livewire method with the password / TOTP code (empty for
    recent-login confirmation). The method returns true or an error message.
--}}
@props([
    'action',
    'message' => null,
    'buttonText' => null,
])

@php
    $reauthMethod = reauthenticationMethod();
    $needsInput = in_array($reauthMethod, ['password', 'totp'], true);
    $message ??= __('Confirm your identity to view this secret.');
    $buttonText ??= __('Confirm');
    $inputId = 'sensitive-confirm-'.\Illuminate\Support\Str::random(8);
@endphp

<div data-sensitive-confirmation
    x-data="{
        credential: '',
        error: '',
        submitting: false,
        async confirmIdentity() {
            if (@js($needsInput) && !this.credential) {
                this.error = @js($reauthMethod === 'totp' ? __('Two-factor code is required.') : __('Password is required.'));
                return;
            }
            this.submitting = true;
            this.error = '';
            try {
                const result = await this.$wire.call(@js($action), this.credential);
                if (result !== true) {
                    this.error = typeof result === 'string' ? result : @js(__('Confirmation failed. Please try again.'));
                }
            } finally {
                this.credential = '';
                this.submitting = false;
            }
        },
    }"
    {{ $attributes->class(['flex w-full flex-col gap-2 rounded-md border border-neutral-200 p-3 dark:border-white/[0.08]']) }}>
    <p class="text-xs text-neutral-600 dark:text-fg-dim">{{ $message }}</p>
    @if ($reauthMethod === 'password')
        <label for="{{ $inputId }}" class="text-xs font-medium">{{ __('Your Password') }}</label>
        <input id="{{ $inputId }}" type="password" x-model="credential" class="input w-full"
            placeholder="{{ __('Enter your password') }}" autocomplete="current-password"
            @keydown.enter.prevent="confirmIdentity()">
    @elseif ($reauthMethod === 'totp')
        <label for="{{ $inputId }}" class="text-xs font-medium">{{ __('Two-factor code') }}</label>
        <input id="{{ $inputId }}" type="text" x-model="credential" class="input w-full"
            placeholder="{{ __('Enter the 6-digit code') }}" inputmode="numeric" autocomplete="one-time-code"
            maxlength="10" @keydown.enter.prevent="confirmIdentity()">
    @else
        <p class="text-xs text-neutral-500 dark:text-fg-faint">
            {{ __('You signed in with single sign-on. If you signed in more than :minutes minutes ago, sign out and sign in again first.', ['minutes' => \App\Support\Reauthentication::windowMinutes()]) }}
        </p>
    @endif
    <p x-cloak x-show="error" x-text="error" class="text-xs text-red-500"></p>
    <div>
        <x-forms.button type="button" :showLoadingIndicator="false" x-bind:disabled="submitting"
            @click="confirmIdentity()">
            {{ $buttonText }}
        </x-forms.button>
    </div>
</div>
