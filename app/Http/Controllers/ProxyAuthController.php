<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationExposureMode;
use App\Models\Application;
use App\Services\ProxyAuth\ApplicationAccessTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sign-in gate for applications in "signed-in users only" mode.
 *
 * The application's proxy (Traefik forwardAuth / Caddy forward_auth) sends every request to
 * check() first. A 2xx answer lets the request through; any other answer, including its
 * Set-Cookie and Location headers, is returned to the browser as is. That is how the callback
 * on the application's own domain sets the session cookie without a separate proxy route.
 */
class ProxyAuthController extends Controller
{
    /** Path on the application's domain that exchanges a one-time code for the session cookie. */
    public const CALLBACK_PATH = '/__paded_auth/callback';

    public function check(Request $request, ApplicationAccessTokens $tokens): Response
    {
        $host = $this->forwardedHost($request);
        $application = $host === null ? null : $this->signedInOnlyApplication($host);
        if ($application === null) {
            return $this->noStore(response('Forbidden', 403));
        }

        $uri = (string) ($request->header('X-Forwarded-Uri') ?: '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);

        if (str_ends_with($path, self::CALLBACK_PATH)) {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $redeemed = $tokens->redeemCode((string) ($query['code'] ?? ''), $application, $host);
            if ($redeemed === null) {
                return $this->noStore(response('Forbidden', 403));
            }

            $response = response('', 302, ['Location' => $redeemed['return_path']]);
            $response->headers->setCookie(Cookie::create(
                ApplicationAccessTokens::COOKIE_NAME,
                $tokens->issueSession($redeemed['user_id'], $application, $host),
                now()->addSeconds(ApplicationAccessTokens::SESSION_TTL_SECONDS),
                '/',
                null,
                $this->forwardedProto($request) === 'https',
                true,
                false,
                Cookie::SAMESITE_LAX,
            ));

            return $this->noStore($response);
        }

        $user = $tokens->userFromSession($this->cookieFromForwardedRequest($request), $application, $host);
        if ($user !== null) {
            return $this->noStore(response('', 200, [
                'X-Paded-User' => (string) $user->id,
                'X-Paded-Email' => (string) $user->email,
            ]));
        }

        $login = proxyAuthPanelUrl().'/proxy-auth/login?'.http_build_query([
            'app' => $application->uuid,
            'host' => $host,
            'rd' => self::safeReturnPath($uri),
        ]);

        return $this->noStore(response('', 302, ['Location' => $login]));
    }

    /**
     * Runs after the panel sign-in: sends the user back to the application's domain with a one-time code.
     */
    public function signIn(Request $request, ApplicationAccessTokens $tokens): RedirectResponse
    {
        $application = Application::query()->where('uuid', (string) $request->query('app'))->first();
        abort_if($application === null || $application->exposureMode() !== ApplicationExposureMode::Login, 404);

        $domains = $this->domainsByHost($application);
        abort_if($domains === [], 404);

        $requestedHost = strtolower((string) $request->query('host'));
        $host = array_key_exists($requestedHost, $domains) ? $requestedHost : array_key_first($domains);

        $code = $tokens->issueCode($request->user(), $application, $host, self::safeReturnPath((string) $request->query('rd')));

        return redirect()->away($domains[$host].self::CALLBACK_PATH.'?'.http_build_query(['code' => $code]));
    }

    /**
     * A same-site path to return to after sign-in; anything that could leave the application becomes "/".
     */
    public static function safeReturnPath(string $uri): string
    {
        if (! str_starts_with($uri, '/')
            || str_starts_with($uri, '//')
            || str_starts_with($uri, '/\\')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $uri)
            || str_ends_with((string) parse_url($uri, PHP_URL_PATH), self::CALLBACK_PATH)) {
            return '/';
        }

        return $uri;
    }

    private function signedInOnlyApplication(string $host): ?Application
    {
        $pattern = '%'.addcslashes($host, '%_\\').'%';

        return Application::query()
            ->where('exposure_mode', ApplicationExposureMode::Login->value)
            ->where(fn ($query) => $query
                ->where('fqdn', 'like', $pattern)
                ->orWhereHas('previews', fn ($previews) => $previews->where('fqdn', 'like', $pattern)))
            ->get()
            ->first(fn (Application $application) => $application->exposureHosts()->contains($host));
    }

    /**
     * Base URL (scheme, host, and path prefix) of each domain the application answers on, keyed by host.
     *
     * @return array<string, string>
     */
    private function domainsByHost(Application $application): array
    {
        $domains = [];
        $fqdns = collect([$application->fqdn])->merge($application->previews()->pluck('fqdn'))->filter();

        foreach ($fqdns->flatMap(fn (string $fqdns) => explode(',', $fqdns)) as $domain) {
            $parts = parse_url(trim($domain));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host === '' || array_key_exists($host, $domains)) {
                continue;
            }

            $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $domains[$host] = "{$scheme}://{$host}{$port}".rtrim((string) ($parts['path'] ?? ''), '/');
        }

        return $domains;
    }

    private function forwardedHost(Request $request): ?string
    {
        $host = strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Host'))[0]));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : null;
    }

    private function forwardedProto(Request $request): string
    {
        return strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Proto', 'https'))[0]));
    }

    /**
     * The application session cookie from the original request, which the proxy forwards in the Cookie header.
     * Read raw: it is signed by ApplicationAccessTokens, not encrypted by the panel.
     */
    private function cookieFromForwardedRequest(Request $request): ?string
    {
        foreach (explode(';', (string) $request->header('Cookie')) as $cookie) {
            [$name, $value] = array_pad(explode('=', trim($cookie), 2), 2, null);
            if ($name === ApplicationAccessTokens::COOKIE_NAME) {
                return $value;
            }
        }

        return null;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
