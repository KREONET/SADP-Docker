<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Static security headers added to every response that does not set them itself.
     *
     * @var array<string, string>
     */
    protected const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    /**
     * HSTS is sent only over HTTPS. It deliberately leaves out includeSubDomains because
     * applications deployed by this instance often live on plain-HTTP sibling subdomains.
     */
    protected const STRICT_TRANSPORT_SECURITY = 'max-age=31536000';

    /**
     * Handle an incoming request.
     *
     * Headers that the response already carries (for example the sandboxing CSP of
     * BrandingLogoController) are left untouched.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = self::HEADERS + [
            'Content-Security-Policy' => $this->contentSecurityPolicy($request),
        ];

        // Evaluated after the inner stack so TrustProxies has applied X-Forwarded-Proto.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = self::STRICT_TRANSPORT_SECURITY;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Builds a policy that keeps Livewire 3 and Alpine working.
     *
     * - script-src needs 'unsafe-inline' for the inline layout/@script blocks and
     *   'unsafe-eval' for Alpine's standard build that Livewire bundles.
     * - style-src needs 'unsafe-inline' for Livewire/Alpine/Monaco inline styles.
     * - connect-src allows ws:/wss: because Reverb (6001) and the terminal server (6002)
     *   run on other ports or on PUSHER_HOST / TERMINAL_HOST, which 'self' does not cover.
     * - worker-src allows blob: for the Monaco editor worker bootstrap.
     * - form-action allows https: (and http: on plain-HTTP panels) besides 'self': the GitHub
     *   App manifest form posts to github.com or a GitHub Enterprise host, and a sign-in form
     *   may redirect to an application domain (proxy sign-in), which browsers check against
     *   form-action as well.
     */
    protected function contentSecurityPolicy(Request $request): string
    {
        $scriptSources = ["'self'", "'unsafe-inline'", "'unsafe-eval'"];
        $styleSources = ["'self'", "'unsafe-inline'"];
        $fontSources = ["'self'", 'data:'];
        $connectSources = ["'self'", 'ws:', 'wss:'];
        $formActions = ["'self'", 'https:'];

        if (! $request->isSecure()) {
            $formActions[] = 'http:';
        }

        if (config('app.name') === 'Coolify Cloud') {
            array_push($scriptSources, 'https://analytics.coollabs.io', 'https://js.sentry-cdn.com', 'https://browser.sentry-cdn.com');
            array_push($connectSources, 'https://analytics.coollabs.io', 'https://*.sentry.io');
        }

        $viteDevServer = $this->viteDevServerOrigin();
        if ($viteDevServer !== null) {
            $scriptSources[] = $viteDevServer;
            $styleSources[] = $viteDevServer;
            $fontSources[] = $viteDevServer;
            $connectSources[] = $viteDevServer;
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scriptSources,
            'style-src' => $styleSources,
            'img-src' => ['*', 'data:', 'blob:'],
            'font-src' => $fontSources,
            'connect-src' => $connectSources,
            'worker-src' => ["'self'", 'blob:'],
            'frame-ancestors' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => $formActions,
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * The Vite dev server origin while `npm run dev` is running locally, so hot reloading keeps working.
     */
    protected function viteDevServerOrigin(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }

        $hotFile = Vite::hotFile();
        if (! is_file($hotFile)) {
            return null;
        }

        $url = trim((string) file_get_contents($hotFile));
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
