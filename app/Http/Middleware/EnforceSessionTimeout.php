<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces idle and absolute session timeouts, disables remember-me logins and
 * optionally restricts every user to a single active session.
 *
 * Activity detection: every request from an authenticated user refreshes the
 * idle timer except background traffic. A request is background traffic when
 * - it carries `X-User-Activity: 0` (sent on Livewire requests by
 *   resources/js/session-activity.js when no trusted user gesture happened since
 *   the previous Livewire request, which is what wire:poll and Echo-triggered
 *   refreshes look like), or
 * - it is a Livewire update without that header whose payload contains no
 *   property updates and only `$refresh`/`__dispatch` calls (or no calls at
 *   all: a bare `wire:poll` commit or a broadcast event listener), or
 * - it is a broadcasting channel authorization request.
 * Livewire sends no marker for polls itself, so a poll that calls a named
 * method (`wire:poll="refreshStatus"`) is only recognised through the header.
 *
 * Expired Livewire update requests get a redirect to the login page: the
 * Livewire 3 client follows it with fetch and then sets
 * `window.location.href` to the final URL (`response.redirected`), so the
 * browser lands on the login page. A 419 would only show Livewire's
 * "This page has expired" confirm dialog.
 */
class EnforceSessionTimeout
{
    public const AUTHENTICATED_AT_KEY = 'security.session.authenticated_at';

    public const LAST_ACTIVITY_KEY = 'security.session.last_activity_at';

    public const SINGLE_SESSION_KEY = 'security.session.single_session_user_id';

    public const EXPIRED_NOTICE_KEY = 'security.session.expired_notice';

    public const ACTIVITY_HEADER = 'X-User-Activity';

    /**
     * Routes whose posted `remember` flag is honoured by Fortify.
     *
     * @var list<string>
     */
    private const REMEMBER_ROUTES = ['login.store', 'register.store', 'two-factor.login.store'];

    /**
     * Livewire calls that never originate from a user gesture.
     *
     * @var list<string>
     */
    private const PASSIVE_LIVEWIRE_METHODS = ['$refresh', '__dispatch'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->stripRememberInput($request);

        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $guard = Auth::guard();
        $user = $guard->user();

        if ($user !== null && method_exists($guard, 'viaRemember') && $guard->viaRemember()) {
            $this->rejectRememberedLogin();
            $user = null;
        }

        if ($user === null) {
            $session->forget([self::AUTHENTICATED_AT_KEY, self::LAST_ACTIVITY_KEY, self::SINGLE_SESSION_KEY]);
            $this->deliverExpiredNotice($request);
        } else {
            if ($session->has(self::AUTHENTICATED_AT_KEY)) {
                $reason = $this->expiryReason($session);
                if ($reason !== null) {
                    return $this->expire($request, $reason);
                }
            }

            $this->startTracking($request);

            if ($this->countsAsUserActivity($request)) {
                $session->put(self::LAST_ACTIVITY_KEY, now()->getTimestamp());
            }
        }

        $response = $next($request);

        // A login during this request (e.g. the Fortify login POST) starts tracking right away.
        if ($guard->check()) {
            $this->startTracking($request);
        }

        return $response;
    }

    /**
     * Whether the request is user activity that refreshes the idle timer (see the class docblock).
     */
    public function countsAsUserActivity(Request $request): bool
    {
        $header = $request->header(self::ACTIVITY_HEADER);
        if ($header !== null) {
            return filter_var($header, FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->is('broadcasting/auth')) {
            return false;
        }

        if (! $this->isLivewireUpdate($request)) {
            return true;
        }

        $components = $request->input('components');
        if (! is_array($components) || $components === []) {
            return true;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                return true;
            }
            if (! empty($component['updates'])) {
                return true;
            }
            foreach ((array) ($component['calls'] ?? []) as $call) {
                $method = is_array($call) ? ($call['method'] ?? null) : null;
                if (! in_array($method, self::PASSIVE_LIVEWIRE_METHODS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fortify honours a posted `remember=1` even though the login form has no checkbox.
     */
    private function stripRememberInput(Request $request): void
    {
        if (! $request->isMethod('POST') || ! $request->routeIs(...self::REMEMBER_ROUTES)) {
            return;
        }

        $request->request->remove('remember');
        $request->query->remove('remember');
        if ($request->isJson()) {
            $request->json()->remove('remember');
        }
    }

    /**
     * Logins restored from a remember-me cookie issued before remember-me was disabled are refused.
     */
    private function rejectRememberedLogin(): void
    {
        $user = Auth::user();

        auditLog('auth.session.remember_rejected', [
            'user_id' => $user?->getAuthIdentifier(),
            'result' => 'denied',
        ], 'warning');

        // logout() also cycles the remember token and queues removal of the recaller cookie.
        Auth::guard()->logout();
    }

    /**
     * Stamps the session the first time it is seen authenticated and applies the single-session rule.
     */
    private function startTracking(Request $request): void
    {
        $session = $request->session();
        $now = now()->getTimestamp();

        if (! $session->has(self::AUTHENTICATED_AT_KEY)) {
            $session->put(self::AUTHENTICATED_AT_KEY, $now);
            $session->put(self::LAST_ACTIVITY_KEY, $now);
            $session->forget(self::EXPIRED_NOTICE_KEY);
        }

        $userId = Auth::id();
        if ($session->get(self::SINGLE_SESSION_KEY) === $userId) {
            return;
        }
        $session->put(self::SINGLE_SESSION_KEY, $userId);

        // Impersonation switches users inside the same session and must not log the target user out.
        if (! config('security.session.single_session') || $session->has('impersonator_id')) {
            return;
        }

        $this->deleteOtherSessions($userId, $session->getId());
    }

    /**
     * Removes every stored session of the user except the current one (database session driver only).
     */
    private function deleteOtherSessions(int|string|null $userId, string $currentSessionId): void
    {
        if ($userId === null || config('session.driver') !== 'database') {
            return;
        }

        try {
            $deleted = DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->where('id', '!=', $currentSessionId)
                ->delete();
        } catch (Throwable) {
            return;
        }

        if ($deleted > 0) {
            auditLog('auth.session.single_session_enforced', [
                'user_id' => $userId,
                'terminated_sessions' => $deleted,
                'result' => 'success',
            ]);
        }
    }

    /**
     * @return 'idle'|'absolute'|null
     */
    private function expiryReason(Session $session): ?string
    {
        $now = now()->getTimestamp();
        $absoluteMinutes = (int) config('security.session.absolute_timeout_minutes');
        $idleMinutes = (int) config('security.session.idle_timeout_minutes');

        $authenticatedAt = (int) $session->get(self::AUTHENTICATED_AT_KEY);
        if ($absoluteMinutes > 0 && $now - $authenticatedAt >= $absoluteMinutes * 60) {
            return 'absolute';
        }

        $lastActivityAt = (int) $session->get(self::LAST_ACTIVITY_KEY, $authenticatedAt);
        if ($idleMinutes > 0 && $now - $lastActivityAt >= $idleMinutes * 60) {
            return 'idle';
        }

        return null;
    }

    /**
     * @param  'idle'|'absolute'  $reason
     */
    private function expire(Request $request, string $reason): Response
    {
        $session = $request->session();
        $now = now()->getTimestamp();

        auditLog('auth.session.expired', [
            'user_id' => Auth::id(),
            'reason' => $reason,
            'session_age_seconds' => $now - (int) $session->get(self::AUTHENTICATED_AT_KEY, $now),
            'idle_seconds' => $now - (int) $session->get(self::LAST_ACTIVITY_KEY, $now),
            'result' => 'success',
        ]);

        Auth::guard()->logout();
        $session->invalidate();
        $session->regenerateToken();
        $session->put(self::EXPIRED_NOTICE_KEY, $reason);

        $loginUrl = route('login');

        if ($this->isLivewireUpdate($request)) {
            return redirect()->to($loginUrl);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => self::expiredMessage($reason),
                'redirect' => $loginUrl,
            ], 401);
        }

        return redirect()->to($loginUrl);
    }

    /**
     * Shows the expiry message on the first full-page render of the login page.
     *
     * The notice is kept in the session (not flashed) because the Livewire client
     * first follows the redirect with fetch, which would consume a flash message
     * before the browser navigates to the login page.
     */
    private function deliverExpiredNotice(Request $request): void
    {
        $session = $request->session();

        if (! $session->has(self::EXPIRED_NOTICE_KEY)
            || ! $request->isMethod('GET')
            || ! $request->routeIs('login')
            || $request->hasHeader('X-Livewire')) {
            return;
        }

        $session->now('error', self::expiredMessage((string) $session->pull(self::EXPIRED_NOTICE_KEY)));
    }

    public static function expiredMessage(string $reason): string
    {
        return $reason === 'idle'
            ? __('Your session expired due to inactivity. Please log in again.')
            : __('Your session has expired. Please log in again to continue.');
    }

    private function isLivewireUpdate(Request $request): bool
    {
        return $request->hasHeader('X-Livewire') && $request->isMethod('POST');
    }
}
