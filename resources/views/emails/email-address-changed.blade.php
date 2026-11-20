<x-emails.layout>
{{ __('The sign-in email address of your account was changed from :email on :time.', ['email' => $previousEmail, 'time' => $changedAt->format('Y-m-d H:i T')]) }}

{{ __('Sign-in and account emails will no longer be sent to this address.') }}

{{ __('If you did not make this change, contact your administrator immediately.') }}
</x-emails.layout>
