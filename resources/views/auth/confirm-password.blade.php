@php
    // SSO accounts have a random password nobody knows; they set their own here instead of confirming one.
    $needsInitialPassword = auth()->user()?->needsInitialPassword() ?? false;
@endphp
<x-layout-simple>
    <x-auth.shell :title="brand_name()" :description="$needsInitialPassword
        ? __('Set a password for your account to continue to this secure area.')
        : __('Confirm your password to continue to this secure area.')">
        <div class="flex flex-col gap-4">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if ($errors->any() || $errors->initialPassword->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ([...$errors->all(), ...$errors->initialPassword->all()] as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            @if ($needsInitialPassword)
                <div class="auth-guidance">
                    <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('You signed in with SSO, so your account has no password you know yet. Set one to continue. With SSO-only login enabled, the password is used only to confirm sensitive actions such as two-factor setup.') }}</p>
                </div>

                <form action="{{ route('password.initial.store') }}" method="POST" class="flex flex-col gap-4">
                    @csrf
                    <x-forms.input required type="password" name="password" autocomplete="new-password" autofocus
                        label="{{ __('New password') }}" />
                    <x-forms.input required type="password" name="password_confirmation" autocomplete="new-password"
                        label="{{ __('Confirm new password') }}" />
                    <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                        {{ __('Set password and continue') }}
                    </x-forms.button>
                </form>

                <details class="text-sm">
                    <summary class="cursor-pointer text-neutral-500 dark:text-fg-dim">{{ __('I already know my password') }}</summary>
                    <form action="/user/confirm-password" method="POST" class="mt-3 flex flex-col gap-4">
                        @csrf
                        <x-forms.input required type="password" name="password" autocomplete="current-password"
                            label="{{ __('input.password') }}" />
                        <x-forms.button class="w-full justify-center" type="submit">
                            {{ __('auth.confirm_password') }}
                        </x-forms.button>
                    </form>
                </details>
            @else
                <div class="auth-guidance">
                    <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('This is a secure area. Please confirm your password before continuing.') }}</p>
                </div>

                <form action="/user/confirm-password" method="POST" class="flex flex-col gap-4">
                    @csrf
                    <x-forms.input required type="password" name="password" autocomplete="current-password" autofocus
                        label="{{ __('input.password') }}" />
                    <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                        {{ __('auth.confirm_password') }}
                    </x-forms.button>
                </form>
            @endif
        </div>
    </x-auth.shell>
</x-layout-simple>
