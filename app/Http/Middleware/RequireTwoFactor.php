<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users who must use two-factor authentication to their profile until they confirm it.
 *
 * `security.two_factor.required_for` selects who must: `none`, `admins` (platform administrators)
 * or `all`. New accounts get `security.two_factor.grace_period_hours` from creation to set it up
 * before they are redirected. Only the pages needed to set up 2FA, sign out or finish another mandatory step stay
 * reachable meanwhile. API (Sanctum token) requests are never affected.
 */
class RequireTwoFactor
{
    /**
     * Session key holding the message shown on the profile page after a redirect.
     */
    public const SESSION_FLASH_KEY = 'two_factor_required';

    /**
     * Fragment of the two-factor section on the profile page.
     */
    public const PROFILE_FRAGMENT = 'two-factor';

    /**
     * Routes a user must reach to set up 2FA, sign out or complete another mandatory step.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'profile',
        'logout',
        'auth.force-password-reset',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'password.initial.store',
        'two-factor.*',
        'team.select',
        'verify.email',
        'verify.verify',
        '*livewire.update',
    ];

    /**
     * Paths matched when a request has no named route (for example before routing finished).
     *
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        'profile',
        'logout',
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
        'user/confirm-password',
        'user/confirmed-password-status',
        'user/initial-password',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-qr-code',
        'user/two-factor-secret-key',
        'user/two-factor-recovery-codes',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $this->isTokenRequest($user)
            || ! static::mustUseTwoFactor($user)
            || $this->isAllowedRequest($request)) {
            return $next($request);
        }

        $message = __('Your account must use two-factor authentication. Set it up to continue.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        $request->session()->flash(self::SESSION_FLASH_KEY, $message);

        return redirect()->to(route('profile').'#'.self::PROFILE_FRAGMENT);
    }

    /**
     * Whether the policy requires this user to use 2FA now and they have not confirmed it yet.
     */
    public static function mustUseTwoFactor(User $user): bool
    {
        if (! static::policyAppliesTo($user) || static::hasConfirmedTwoFactor($user)) {
            return false;
        }

        return static::gracePeriodEndsAt($user) === null;
    }

    /**
     * When the grace period for setting up 2FA ends, or null when the user is not in one.
     */
    public static function gracePeriodEndsAt(User $user): ?Carbon
    {
        $hours = (int) config('security.two_factor.grace_period_hours', 0);

        if ($hours <= 0
            || $user->created_at === null
            || ! static::policyAppliesTo($user)
            || static::hasConfirmedTwoFactor($user)) {
            return null;
        }

        $endsAt = $user->created_at->copy()->addHours($hours);

        return $endsAt->isFuture() ? $endsAt : null;
    }

    /**
     * Whether `security.two_factor.required_for` covers this user.
     *
     * Users without a usable local password (SSO accounts) are exempt: Fortify needs a password
     * confirmation to enable 2FA, and their identity provider enforces its own MFA.
     */
    public static function policyAppliesTo(User $user): bool
    {
        $requiredFor = (string) config('security.two_factor.required_for', 'none');

        $applies = match ($requiredFor) {
            'all' => true,
            'admins' => $user->canOperatePlatform() || (bool) $user->is_auditor,
            default => false,
        };

        return $applies && $user->requiresPasswordConfirmation();
    }

    public static function hasConfirmedTwoFactor(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }

    private function isTokenRequest(User $user): bool
    {
        return $user->currentAccessToken() instanceof PersonalAccessToken;
    }

    private function isAllowedRequest(Request $request): bool
    {
        if ($request->route() !== null && $request->routeIs(...self::ALLOWED_ROUTES)) {
            return true;
        }

        return in_array(trim($request->path(), '/'), self::ALLOWED_PATHS, true);
    }
}
