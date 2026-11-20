<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['fqdn' => null]));
    Once::flush();
    // With no users, /login redirects to the first-user registration.
    User::factory()->create();
});

/**
 * @return array<string, string>
 */
function cspDirectives(string $policy): array
{
    return collect(explode(';', $policy))
        ->map(fn (string $directive) => trim($directive))
        ->filter()
        ->mapWithKeys(function (string $directive): array {
            [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');

            return [$name => $value];
        })
        ->all();
}

it('adds the security headers to rendered pages', function () {
    $response = $this->get('/login');

    $response->assertSuccessful()
        ->assertSee('csrf-token', false)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
});

it('sends a content security policy that keeps Livewire, Alpine and websockets working', function () {
    $directives = cspDirectives($this->get('/login')->headers->get('Content-Security-Policy'));

    expect($directives)
        ->toHaveKeys(['default-src', 'script-src', 'style-src', 'connect-src', 'frame-ancestors', 'object-src', 'base-uri', 'form-action'])
        ->and($directives['default-src'])->toBe("'self'")
        ->and($directives['frame-ancestors'])->toBe("'self'")
        ->and($directives['object-src'])->toBe("'none'")
        ->and($directives['base-uri'])->toBe("'self'")
        ->and($directives['script-src'])->toContain("'self'", "'unsafe-inline'", "'unsafe-eval'")
        ->and($directives['style-src'])->toContain("'unsafe-inline'")
        ->and($directives['connect-src'])->toContain("'self'", 'ws:', 'wss:')
        ->and($directives['worker-src'])->toContain('blob:')
        ->and($directives['form-action'])->toStartWith("'self'");
});

it('sends HSTS only over HTTPS', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ])->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('does not allow plain HTTP form targets from an HTTPS page', function () {
    $policy = $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ])->headers->get('Content-Security-Policy');

    expect(cspDirectives($policy)['form-action'])->toBe("'self' https:");
});

it('adds the headers to JSON and error responses too', function () {
    $this->getJson('/api/health')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/this-page-does-not-exist')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('keeps headers that a response already sets', function () {
    $response = (new SecurityHeaders)->handle(Request::create('/branding/logo'), fn () => response('logo', 200, [
        'Content-Security-Policy' => "default-src 'none'; sandbox",
        'X-Frame-Options' => 'DENY',
    ]));

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('allows the Vite dev server only in the local environment', function () {
    $hotFile = storage_path('framework/testing-vite-hot');
    file_put_contents($hotFile, 'http://localhost:5173');
    Vite::useHotFile($hotFile);

    try {
        $middleware = new SecurityHeaders;
        $handle = fn () => $middleware->handle(Request::create('/login'), fn () => response('ok'))->headers->get('Content-Security-Policy');

        expect($handle())->not->toContain('localhost:5173');

        app()->detectEnvironment(fn () => 'local');
        expect(cspDirectives($handle())['script-src'])->toContain('http://localhost:5173');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        Vite::useHotFile(public_path('hot'));
        @unlink($hotFile);
    }
});
