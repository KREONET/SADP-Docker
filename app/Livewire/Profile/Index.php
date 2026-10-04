<?php

namespace App\Livewire\Profile;

use App\Actions\User\DeleteUserAccount;
use App\Services\AvatarStorageService;
use App\Support\InterfaceLocale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    public int $userId;

    public string $email;

    public string $current_password;

    public string $new_password;

    public string $new_password_confirmation;

    #[Validate('required')]
    public string $name;

    public string $new_email = '';

    public string $email_verification_code = '';

    public bool $show_email_change = false;

    public bool $show_verification = false;

    public bool $uses_sso = false;

    public ?string $sso_provider_label = null;

    public $avatar;

    /**
     * Interface language; "default" follows the instance default language.
     */
    public string $locale = 'default';

    public function uploadAvatar(AvatarStorageService $avatarStorage): bool
    {
        try {
            $this->validate([
                'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            ]);

            $avatarStorage->store(Auth::user(), $this->avatar);
            $this->reset('avatar');
            $this->dispatch('avatar-updated', url: profile_avatar_url(Auth::user()->fresh()));
            $this->dispatch('success', __('Profile picture updated.'));

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function removeAvatar(AvatarStorageService $avatarStorage): void
    {
        try {
            $avatarStorage->delete(Auth::user());
            $this->dispatch('avatar-updated', url: null);
            $this->dispatch('success', __('Profile picture removed.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function mount()
    {
        $this->userId = Auth::id();
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->locale = InterfaceLocale::isAvailable(Auth::user()->locale) ? Auth::user()->locale : 'default';

        $oauthIdentity = Auth::user()->oauthIdentities()->latest('id')->first();
        $this->uses_sso = $oauthIdentity !== null;
        $this->sso_provider_label = $oauthIdentity ? $this->providerLabel($oauthIdentity->provider) : null;

        // Check if there's a pending email change
        if (! $this->uses_sso && Auth::user()->hasEmailChangeRequest()) {
            $this->new_email = Auth::user()->pending_email;
            $this->show_verification = true;
        }
    }

    public function submit()
    {
        try {
            $this->validate([
                'name' => 'required',
            ]);
            Auth::user()->update([
                'name' => $this->name,
            ]);
            auditLog('ui.user.profile_updated', $this->auditContext(['changed_fields' => ['name']]));

            $this->dispatch('success', __('Profile updated.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updateLocale()
    {
        $this->validate([
            'locale' => ['required', 'in:default,'.implode(',', array_keys(InterfaceLocale::available()))],
        ]);

        Auth::user()->update(['locale' => $this->locale === 'default' ? null : $this->locale]);
        auditLog('ui.user.locale_updated', $this->auditContext(['locale' => $this->locale]));

        // Reload so the navigation and every component render in the new language.
        return $this->redirect(route('profile'));
    }

    public function requestEmailChange()
    {
        try {
            if ($this->rejectSsoEmailChange()) {
                return;
            }

            // For self-hosted, check if email is enabled
            if (! isCloud()) {
                $settings = instanceSettings();
                if (! $settings->smtp_enabled && ! $settings->resend_enabled) {
                    $this->dispatch('error', __('Email functionality is not configured. Please contact your administrator.'));

                    return;
                }
            }

            $this->validate([
                'new_email' => ['required', 'email', 'unique:users,email'],
            ]);

            $this->new_email = strtolower($this->new_email);

            // Skip rate limiting in development mode
            if (! isDev()) {
                // Rate limit by current user's email (1 request per 2 minutes)
                $userEmailKey = 'email-change:user:'.Auth::id();
                if (! RateLimiter::attempt($userEmailKey, 1, function () {}, 120)) {
                    $seconds = RateLimiter::availableIn($userEmailKey);
                    $this->dispatch('error', __('Too many requests. Please wait :seconds seconds before trying again.', ['seconds' => $seconds]));

                    return;
                }

                // Rate limit by new email address (3 requests per hour per email)
                $newEmailKey = 'email-change:email:'.md5($this->new_email);
                if (! RateLimiter::attempt($newEmailKey, 3, function () {}, 3600)) {
                    $this->dispatch('error', __('This email address has received too many verification requests. Please try again later.'));

                    return;
                }

                // Additional rate limit by IP address (5 requests per hour)
                $ipKey = 'email-change:ip:'.request()->ip();
                if (! RateLimiter::attempt($ipKey, 5, function () {}, 3600)) {
                    $this->dispatch('error', __('Too many requests from your IP address. Please try again later.'));

                    return;
                }
            }

            Auth::user()->requestEmailChange($this->new_email);
            auditLog('ui.user.email_change_requested', $this->auditContext());

            $this->show_email_change = false;
            $this->show_verification = true;

            $this->dispatch('success', __('Verification code sent to :email', ['email' => $this->new_email]));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function verifyEmailChange()
    {
        try {
            if ($this->rejectSsoEmailChange()) {
                return;
            }

            $this->validate([
                'email_verification_code' => ['required', 'string', 'size:6'],
            ]);

            // Skip rate limiting in development mode
            if (! isDev()) {
                // Rate limit verification attempts (5 attempts per 10 minutes)
                $verifyKey = 'email-verify:user:'.Auth::id();
                if (! RateLimiter::attempt($verifyKey, 5, function () {}, 600)) {
                    $seconds = RateLimiter::availableIn($verifyKey);
                    $minutes = ceil($seconds / 60);
                    $this->dispatch('error', __('Too many verification attempts. Please wait :minutes minutes before trying again.', ['minutes' => $minutes]));

                    // If too many failed attempts, clear the email change request for security
                    if (RateLimiter::attempts($verifyKey) >= 10) {
                        Auth::user()->clearEmailChangeRequest();
                        $this->new_email = '';
                        $this->email_verification_code = '';
                        $this->show_verification = false;
                        $this->dispatch('error', __('Email change request cancelled due to too many failed attempts. Please start over.'));
                    }

                    return;
                }
            }

            if (! Auth::user()->isEmailChangeCodeValid($this->email_verification_code)) {
                $this->dispatch('error', __('Invalid or expired verification code.'));

                return;
            }

            if (Auth::user()->confirmEmailChange($this->email_verification_code)) {
                // Clear rate limiters on successful verification (only in production)
                if (! isDev()) {
                    $verifyKey = 'email-verify:user:'.Auth::id();
                    RateLimiter::clear($verifyKey);
                }

                $this->email = Auth::user()->email;
                $this->new_email = '';
                $this->email_verification_code = '';
                $this->show_verification = false;

                $this->dispatch('success', __('Email address updated successfully.'));
                $this->dispatch('close-email-change-modal');
                auditLog('ui.user.email_changed', $this->auditContext());
            } else {
                $this->dispatch('error', __('Failed to update email address.'));
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resendVerificationCode()
    {
        try {
            if ($this->rejectSsoEmailChange()) {
                return;
            }

            // Check if there's a pending request
            if (! Auth::user()->hasEmailChangeRequest()) {
                $this->dispatch('error', __('No pending email change request.'));

                return;
            }

            // Check if enough time has passed (at least half of the expiry time)
            $expiryMinutes = config('constants.email_change.verification_code_expiry_minutes', 10);
            $halfExpiryMinutes = $expiryMinutes / 2;
            $codeExpiry = Auth::user()->email_change_code_expires_at;
            $timeSinceCreated = $codeExpiry->subMinutes($expiryMinutes)->diffInMinutes(now());

            if ($timeSinceCreated < $halfExpiryMinutes) {
                $minutesToWait = ceil($halfExpiryMinutes - $timeSinceCreated);
                $this->dispatch('error', __('Please wait :minutes more minutes before requesting a new code.', ['minutes' => $minutesToWait]));

                return;
            }

            $pendingEmail = Auth::user()->pending_email;

            // Skip rate limiting in development mode
            if (! isDev()) {
                // Rate limit by email address
                $newEmailKey = 'email-change:email:'.md5(strtolower($pendingEmail));
                if (! RateLimiter::attempt($newEmailKey, 3, function () {}, 3600)) {
                    $this->dispatch('error', __('This email address has received too many verification requests. Please try again later.'));

                    return;
                }
            }

            // Generate and send new code
            Auth::user()->requestEmailChange($pendingEmail);

            $this->dispatch('success', __('New verification code sent to :email', ['email' => $pendingEmail]));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function cancelEmailChange()
    {
        Auth::user()->clearEmailChangeRequest();
        $this->new_email = '';
        $this->email_verification_code = '';
        $this->show_email_change = false;
        $this->show_verification = false;

        $this->dispatch('success', __('Email change request cancelled.'));
    }

    public function showEmailChangeForm()
    {
        if ($this->rejectSsoEmailChange()) {
            return;
        }

        $this->show_email_change = true;
        $this->new_email = '';
    }

    private function rejectSsoEmailChange(): bool
    {
        if (! Auth::user()->hasSsoIdentity()) {
            return false;
        }

        $this->uses_sso = true;
        $this->show_email_change = false;
        $this->show_verification = false;
        $this->dispatch('error', __('Email addresses managed by SSO cannot be changed in Coolify.'));

        return true;
    }

    public function resetPassword()
    {
        try {
            $this->validate([
                'current_password' => ['required'],
                'new_password' => ['required', Password::defaults(), 'confirmed'],
            ]);
            if (! Hash::check($this->current_password, auth()->user()->password)) {
                $this->dispatch('error', __('Current password is incorrect.'));

                return;
            }
            if ($this->new_password !== $this->new_password_confirmation) {
                $this->dispatch('error', __('The two new passwords does not match.'));

                return;
            }
            auth()->user()->update([
                'password' => Hash::make($this->new_password),
            ]);
            auditLog('ui.user.password_changed', $this->auditContext());
            $this->dispatch('success', __('Password updated.'));
            $this->current_password = '';
            $this->new_password = '';
            $this->new_password_confirmation = '';
            $this->dispatch('reloadWindow');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'oidc' => 'OIDC',
            default => str($provider)->headline()->toString(),
        };
    }

    /**
     * Withdraw: permanently delete the signed-in account. The root user cannot withdraw.
     */
    public function deleteAccount($password = '')
    {
        $user = Auth::user();
        if ($user->id === 0) {
            $this->dispatch('error', __('The root account cannot be deleted.'));

            return;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return __('The provided password is incorrect.');
        }

        try {
            DeleteUserAccount::execute($user, 'self');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        // logout() would cycle the remember token and save the deleted user back into the database
        Auth::guard('web')->logoutCurrentDevice();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login');
    }

    private function auditContext(array $context = []): array
    {
        $user = Auth::user();

        return array_merge([
            'team_id' => $user->currentTeam()?->id,
            'resource' => 'user',
            'user_name' => $user->name,
        ], $context);
    }

    public function render()
    {
        return view('livewire.profile.index');
    }
}
