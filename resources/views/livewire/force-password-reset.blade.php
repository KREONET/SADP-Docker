<x-auth.shell :title="__('Set a new password')"
    :description="__('Your password must be updated before you can continue to the dashboard.')">
    <form class="flex flex-col gap-4" wire:submit="submit">
        @if ($passwordExpired)
            <x-callout type="warning" :title="__('Your password has expired')">
                {{ __('Passwords must be changed every :days days. Choose a new password that you have not used recently.', ['days' => config('security.password.expires_after_days')]) }}
            </x-callout>
        @endif

        <x-forms.input id="email" type="email" readonly :label="__('Email')" />
        <x-forms.input id="password" type="password" autocomplete="new-password" autofocus :label="__('New password')"
            required />
        <x-forms.input id="password_confirmation" type="password" autocomplete="new-password"
            :label="__('Confirm new password')" required />

        <div class="auth-guidance">
            <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
            <p>{{ __('Use at least :count characters with uppercase, lowercase, number, and symbol.', ['count' => config('security.password.min_length')]) }}</p>
        </div>

        <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
            {{ __('Reset password') }}
        </x-forms.button>
    </form>
</x-auth.shell>
