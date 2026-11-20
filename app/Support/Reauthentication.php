<?php

namespace App\Support;

use App\Http\Middleware\EnforceSessionTimeout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Component;
use Throwable;

/**
 * Re-authentication before sensitive actions and reads.
 *
 * Users with a usable local password confirm with it. Users without one (SSO)
 * confirm with a current TOTP code when they have confirmed two-factor
 * authentication, otherwise they must have signed in recently. A successful
 * confirmation is remembered in the session for a short window so revealing
 * several secrets in a row does not prompt on every one of them.
 */
final class Reauthentication
{
    public const METHOD_PASSWORD = 'password';

    public const METHOD_TOTP = 'totp';

    public const METHOD_RECENT_LOGIN = 'recent_login';

    public const METHOD_NONE = 'none';

    public const CONFIRMED_AT_KEY = 'sensitive_confirmed_at';

    /**
     * Session keys that may hold the time the session was authenticated, in lookup order.
     *
     * @var list<string>
     */
    private const AUTHENTICATED_AT_FALLBACK_KEYS = ['authenticated_at', 'auth.authenticated_at'];

    private const DEFAULT_WINDOW_MINUTES = 15;

    private const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * How the given (or current) user confirms their identity.
     *
     * @return self::METHOD_*
     */
    public static function method(?User $user = null): string
    {
        $user ??= self::currentUser();

        if ($user === null) {
            return self::METHOD_NONE;
        }

        if ($user->requiresPasswordConfirmation()) {
            return self::METHOD_PASSWORD;
        }

        if (self::hasConfirmedTwoFactor($user)) {
            return self::METHOD_TOTP;
        }

        return self::METHOD_RECENT_LOGIN;
    }

    /**
     * Whether the confirmation UI needs no input from the user (recent sign-in is checked server side).
     */
    public static function needsNoInput(?User $user = null): bool
    {
        return self::method($user) === self::METHOD_RECENT_LOGIN;
    }

    /**
     * Verifies the credential for the current user and remembers a successful confirmation.
     *
     * @param  mixed  $credential  Password or TOTP code; ignored for recent-login confirmation.
     */
    public static function verify(mixed $credential, ?Component $component = null): bool
    {
        $user = self::currentUser();
        $method = self::method($user);

        if ($user === null) {
            self::fail($component, $method, __('The provided password is incorrect.'));

            return false;
        }

        $limiterKey = 'reauthentication:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($limiterKey, self::maxAttempts())) {
            self::fail($component, $method, __('Too many confirmation attempts. Please try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($limiterKey),
            ]), 'denied', notify: true);

            return false;
        }

        $passed = match ($method) {
            self::METHOD_PASSWORD => is_string($credential) && $credential !== '' && Hash::check($credential, $user->password),
            self::METHOD_TOTP => self::verifyTotp($user, $credential),
            self::METHOD_RECENT_LOGIN => self::hasRecentLogin(),
            default => false,
        };

        if (! $passed) {
            if ($method !== self::METHOD_RECENT_LOGIN) {
                RateLimiter::hit($limiterKey, 60);
            }
            self::fail($component, $method, self::failureMessage($method), notify: $method === self::METHOD_RECENT_LOGIN);

            return false;
        }

        RateLimiter::clear($limiterKey);
        self::markConfirmed();

        return true;
    }

    public static function failureMessage(string $method): string
    {
        return match ($method) {
            self::METHOD_TOTP => __('The provided two-factor code is invalid.'),
            self::METHOD_RECENT_LOGIN => __('For security, please sign in again to confirm this action.'),
            default => __('The provided password is incorrect.'),
        };
    }

    public static function markConfirmed(): void
    {
        if (app()->bound('session')) {
            session()->put(self::CONFIRMED_AT_KEY, now()->getTimestamp());
        }
    }

    public static function forgetConfirmation(): void
    {
        if (app()->bound('session')) {
            session()->forget(self::CONFIRMED_AT_KEY);
        }
    }

    /**
     * Whether the user confirmed their identity within the re-authentication window.
     */
    public static function hasRecentConfirmation(): bool
    {
        if (self::currentUser() === null || ! app()->bound('session')) {
            return false;
        }

        return self::isWithinWindow(session()->get(self::CONFIRMED_AT_KEY));
    }

    /**
     * Whether the current session was authenticated within the re-authentication window.
     */
    public static function hasRecentLogin(): bool
    {
        return self::isWithinWindow(self::authenticatedAt());
    }

    /**
     * Unix timestamp of the moment the current session was authenticated, if known.
     */
    public static function authenticatedAt(): ?int
    {
        if (! app()->bound('session')) {
            return null;
        }

        $keys = self::AUTHENTICATED_AT_FALLBACK_KEYS;
        if (defined(EnforceSessionTimeout::class.'::AUTHENTICATED_AT_KEY')) {
            array_unshift($keys, EnforceSessionTimeout::AUTHENTICATED_AT_KEY);
        }

        foreach ($keys as $key) {
            $value = session()->get($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    public static function windowMinutes(): int
    {
        return max(1, (int) config('security.reauthentication.window_minutes', self::DEFAULT_WINDOW_MINUTES));
    }

    private static function maxAttempts(): int
    {
        return max(1, (int) config('security.reauthentication.max_attempts', self::DEFAULT_MAX_ATTEMPTS));
    }

    private static function isWithinWindow(mixed $timestamp): bool
    {
        if (! is_numeric($timestamp) || (int) $timestamp <= 0) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $timestamp;

        return $age >= 0 && $age < self::windowMinutes() * 60;
    }

    private static function hasConfirmedTwoFactor(User $user): bool
    {
        return filled($user->two_factor_secret) && filled($user->two_factor_confirmed_at);
    }

    private static function verifyTotp(User $user, mixed $code): bool
    {
        if (! is_string($code) && ! is_int($code)) {
            return false;
        }

        $code = preg_replace('/\s+/', '', (string) $code);
        if ($code === '' || ! ctype_digit($code)) {
            return false;
        }

        try {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

            return (bool) app(TwoFactorAuthenticationProvider::class)->verify($secret, $code);
        } catch (Throwable) {
            return false;
        }
    }

    private static function fail(?Component $component, string $method, string $message, string $result = 'failure', bool $notify = false): void
    {
        auditLog('auth.reauthentication.failed', [
            'reauthentication_method' => $method,
            'result' => $result,
        ], 'warning');

        if ($component === null) {
            return;
        }

        $component->addError('password', $message);

        // Recent-login confirmation has no input field that could show the error.
        if ($notify) {
            $component->dispatch('error', $message);
        }
    }

    private static function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
