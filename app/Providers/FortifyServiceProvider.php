<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\OauthSetting;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                // First user (root) will be redirected to /settings instead of / on registration.
                if ($request->user()->currentTeam()?->id === 0) {
                    return redirect()->route('settings.index');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::registerView(function () {
            $isFirstUser = User::count() === 0;

            $settings = instanceSettings();
            if (! $settings->isPasswordRegistrationAllowed()) {
                return redirect()->route('login');
            }

            return view('auth.register', [
                'isFirstUser' => $isFirstUser,
            ]);
        });

        Fortify::loginView(function () {
            $settings = instanceSettings();
            $enabled_oauth_providers = OauthSetting::where('enabled', true)->whereIn('provider', OauthSetting::SUPPORTED_PROVIDERS)->get();
            $users = User::count();
            if ($users == 0 && $settings->isPasswordRegistrationAllowed()) {
                // If there are no users and password registration is allowed, redirect to registration.
                return redirect()->route('register');
            }

            return view('auth.login', [
                'is_registration_enabled' => $settings->isPasswordRegistrationAllowed(),
                'enabled_oauth_providers' => $enabled_oauth_providers,
                'is_sso_only' => $settings->isSsoOnlyLoginActive(),
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            // A password sign-in is never an SSO session, even if an abandoned OAuth
            // two-factor challenge left the marker in this session.
            $request->session()->forget(OauthLoginService::SSO_SESSION_KEY);

            $email = strtolower($request->email);
            $user = User::where('email', $email)->with('teams')->first();
            if (! $user) {
                return null;
            }

            if (! $this->passesAccountLockout($user, $request)) {
                return null;
            }

            if (Hash::check($request->password, $user->password)) {
                if ($user->isDeactivated()) {
                    auditLog('auth.password_login.blocked_deactivated', ['user_id' => $user->id], 'warning');

                    throw ValidationException::withMessages([
                        Fortify::username() => __('This account has been deactivated.'),
                    ]);
                }
                if (! instanceSettings()->isPasswordLoginAllowedFor($user)) {
                    auditLog('auth.password_login.blocked_sso_only', ['user_id' => $user->id], 'warning');

                    throw ValidationException::withMessages([
                        Fortify::username() => __('auth.sso_only'),
                    ]);
                }
                $user->updated_at = now();
                $user->save();

                // Check if user has a pending invitation they haven't accepted yet
                $invitation = TeamInvitation::whereEmail($email)->first();
                if ($invitation && $invitation->isValid()) {
                    // User is logging in for the first time after being invited
                    // Attach them to the invited team if not already attached
                    if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                        $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
                    }
                    $team = $invitation->team;
                    $invitation->delete();
                    session(['currentTeam' => $team]);
                } else {
                    // Restore the last active team; only fall back when unambiguous.
                    $team = $user->resolveStoredTeam();
                    if (! $team && $user->teams->isEmpty()) {
                        $team = $user->recreate_personal_team();
                    }
                    if ($team) {
                        session(['currentTeam' => $team]);
                    }
                    // Otherwise (multiple teams, no stored choice) leave the session
                    // team unset so the user is sent to the team-selection screen.
                }

                return $user;
            }

            $this->recordFailedPasswordLogin($user);

            return null;
        });
        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });
        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', ['request' => $request]);
        });
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::confirmPasswordView(function () {
            return view('auth.confirm-password');
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });
    }

    /**
     * Refuse sign-in while the account is locked. The lockout message is shown only
     * to someone who knows the password, so a wrong password on a locked account gets
     * the same generic failure as an unknown email.
     */
    private function passesAccountLockout(User $user, Request $request): bool
    {
        if ($user->locked_until !== null && ! $user->isLockedOut()) {
            // The lock expired: start counting from zero again.
            $user->unlockAccount();
        }

        if (! $user->isLockedOut()) {
            return true;
        }

        auditLog('auth.login.locked', $this->lockoutAuditContext($user) + [
            'reason' => 'attempt_while_locked',
            'locked_until' => $user->locked_until?->toIso8601String(),
            'result' => 'denied',
        ], 'warning');

        if (is_string($request->password) && Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                Fortify::username() => __('This account is temporarily locked after too many failed sign-in attempts. Try again in :minutes minutes or ask an administrator to unlock it.', [
                    'minutes' => max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60)),
                ]),
            ]);
        }

        return false;
    }

    private function recordFailedPasswordLogin(User $user): void
    {
        $user->recordFailedLogin();

        if (! $user->isLockedOut()) {
            return;
        }

        $context = $this->lockoutAuditContext($user) + [
            'reason' => 'max_attempts_reached',
            'failed_attempts' => $user->failed_login_attempts,
            'locked_until' => $user->locked_until?->toIso8601String(),
        ];
        auditLog('auth.login.locked', $context + ['result' => 'denied'], 'warning');
        notifySecurityAlert('account_locked', 'Account locked after repeated failed sign-ins', $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function lockoutAuditContext(User $user): array
    {
        return [
            'resource' => 'user',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
        ];
    }
}
