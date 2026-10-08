<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminIpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Restricts administrator access to the addresses in `instance_settings.admin_allowed_ips`.
 *
 * When the allowlist is set and the client address ({@see AdminIpAllowlist::clientIp()}) is not in it:
 * - a signed-in platform administrator or auditor is signed out and shown a 403 page;
 * - every request to the instance admin area (/platform, /settings), including Livewire
 *   updates of components rendered on those pages, gets a 403.
 * Other users and pages are not affected. The login page stays reachable so administrators can sign in
 * from an allowed address.
 *
 * [한국어] 관리자 IP 허용 목록 미들웨어입니다. 허용되지 않은 주소에서 접속한 플랫폼 관리자·감사자는 로그아웃되고 403 화면을 봅니다.
 * /platform, /settings 경로(그리고 그 화면의 Livewire 요청)는 누구든 허용된 주소에서만 열 수 있습니다.
 * 팀 사용자의 터미널(/terminal)은 제한하지 않습니다. 관리자·감사자는 허용되지 않은 주소에서 이미 로그아웃됩니다.
 */
class RestrictAdminIps
{
    public const AUDIT_EVENT = 'auth.admin_ip.denied';

    /**
     * Path patterns of the instance admin area.
     *
     * @var list<string>
     */
    public const RESTRICTED_PATHS = [
        'platform',
        'platform/*',
        'settings',
        'settings/*',
    ];

    /**
     * Endpoints the terminal websocket server calls on 127.0.0.1 with the browser's session cookie.
     * The browser opened an admin-area page to start the terminal, so those calls carry no client address.
     *
     * @var list<string>
     */
    private const INTERNAL_TERMINAL_PATHS = [
        'terminal/auth',
        'terminal/auth/ips',
        'terminal/session',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminIpAllowlist::isConfigured() || $this->isInternalTerminalCall($request)) {
            return $next($request);
        }

        $clientIp = AdminIpAllowlist::clientIp($request);
        if (AdminIpAllowlist::allows($clientIp, AdminIpAllowlist::configuredRanges())) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();
        if ($user instanceof User && static::isPrivilegedUser($user)) {
            return $this->signOutAndDeny($request, $user, $clientIp);
        }

        if ($this->targetsRestrictedArea($request)) {
            auditLog(self::AUDIT_EVENT, [
                'client_ip' => $clientIp,
                'path' => $request->path(),
                'reason' => 'restricted_path',
                'result' => 'denied',
            ], 'warning');

            return $this->deniedResponse($request);
        }

        return $next($request);
    }

    /**
     * Platform administrators and auditors are bound to the administrator allowlist.
     */
    public static function isPrivilegedUser(User $user): bool
    {
        return (bool) $user->is_auditor || (bool) $user->isInstanceAdmin();
    }

    /**
     * Whether the request opens the admin area, directly or as a Livewire update of a component
     * rendered there. The component path comes from the snapshot memo, whose checksum Livewire
     * verifies afterwards, so a client cannot move a component out of the admin area.
     */
    private function targetsRestrictedArea(Request $request): bool
    {
        if ($request->is(...self::RESTRICTED_PATHS)) {
            return true;
        }

        if (! $this->isLivewireUpdate($request)) {
            return false;
        }

        $components = $request->input('components');
        if (! is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $path = is_array($snapshot) ? data_get($snapshot, 'memo.path') : null;

            if (is_string($path) && $this->isRestrictedPath($path)) {
                return true;
            }
        }

        return false;
    }

    private function isRestrictedPath(string $path): bool
    {
        $path = trim($path, '/');

        foreach (self::RESTRICTED_PATHS as $pattern) {
            if (str($path)->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isLivewireUpdate(Request $request): bool
    {
        if ($request->route() !== null && $request->routeIs('*livewire.update')) {
            return true;
        }

        return str_ends_with(trim($request->path(), '/'), 'livewire/update');
    }

    private function isInternalTerminalCall(Request $request): bool
    {
        $remoteAddress = (string) $request->server->get('REMOTE_ADDR');

        return in_array(trim($request->path(), '/'), self::INTERNAL_TERMINAL_PATHS, true)
            && in_array($remoteAddress, ['127.0.0.1', '::1'], true)
            && ! $request->headers->has('x-forwarded-for');
    }

    private function signOutAndDeny(Request $request, User $user, ?string $clientIp): Response
    {
        $context = [
            'client_ip' => $clientIp,
            'path' => $request->path(),
            'reason' => 'admin_session',
            'result' => 'denied',
        ];
        auditLog(self::AUDIT_EVENT, $context, 'warning');
        notifySecurityAlert('admin_ip_denied', 'Administrator access from an address outside the allowlist', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'client_ip' => $clientIp,
        ]);

        Auth::guard('web')->logout();
        try {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (Throwable) {
            // Without a session there is nothing left to invalidate.
        }

        return $this->deniedResponse($request, signedOut: true);
    }

    private function deniedResponse(Request $request, bool $signedOut = false): Response
    {
        $message = __('Administrator access is restricted to approved IP addresses.');

        if ($request->expectsJson() && ! $this->isLivewireUpdate($request)) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return response()->view('errors.admin-ip-restricted', ['message' => $message, 'signedOut' => $signedOut], Response::HTTP_FORBIDDEN);
    }
}
