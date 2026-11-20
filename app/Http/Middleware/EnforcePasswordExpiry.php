<?php

namespace App\Http\Middleware;

use App\Services\Auth\OauthLoginService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users whose local password is older than `security.password.expires_after_days`
 * to the forced password change screen. Sessions that signed in through OAuth/OIDC do
 * not use the local password and are exempt.
 */
class EnforcePasswordExpiry
{
    /**
     * Paths that stay reachable while the password change is pending.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PATHS = [
        'force-password-reset',
        'two-factor-challenge',
        'livewire/update',
        'logout',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        if (
            $request->session()->has(OauthLoginService::SSO_SESSION_KEY)
            || $request->session()->get('impersonating')
            || $request->routeIs('auth.force-password-reset')
            || in_array($request->path(), self::ALLOWED_PATHS, true)
        ) {
            return $next($request);
        }

        if (! $user->passwordHasExpired()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Your password has expired. Set a new password to continue.')], 403);
        }

        return redirect()->route('auth.force-password-reset');
    }
}
