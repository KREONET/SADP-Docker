<?php

namespace App\Providers;

use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\OidcTokenValidator;
use App\Auth\Oidc\Socialite\OidcProvider;
use App\Models\PersonalAccessToken;
use App\Models\Server;
use App\Models\User;
use App\Observers\UserPasswordObserver;
use App\Rules\SafeExternalUrl;
use App\Support\ReverbSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Stripe\StripeClient;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeClient::class, fn () => new StripeClient(config('subscription.stripe_api_key')));
    }

    public function boot(): void
    {
        $this->configureCommands();
        $this->configureModels();
        $this->configurePasswords();
        $this->configureLoginTracking();
        $this->configureSanctumModel();
        $this->configureGitHubHttp();
        $this->configureGitLabHttp();
        $this->configureOidcSocialite();
        $this->configureQueue();
        $this->configureReverbAllowedOrigins();
    }

    /**
     * The Reverb server also accepts connections from the instance domain saved in the
     * settings, not only from APP_URL. The database may be unavailable while Reverb
     * starts, so the origins from the environment are kept on any error.
     *
     * @param  array<int, string>|null  $argv
     */
    private function configureReverbAllowedOrigins(?array $argv = null): void
    {
        $argv ??= $_SERVER['argv'] ?? [];
        if (! $this->app->runningInConsole() || ($argv[1] ?? null) !== 'reverb:start') {
            return;
        }

        try {
            config(['reverb.apps.apps.0.allowed_origins' => ReverbSettings::withInstanceUrls(
                (array) config('reverb.apps.apps.0.allowed_origins', []),
                [instanceSettings()->fqdn],
            )]);
        } catch (Throwable) {
            // Keep the origins from the environment.
        }
    }

    /**
     * Queue workers are long-running processes, so once() values (e.g. instanceSettings())
     * and the Server identity map would stay stale across jobs. Flush them before each job,
     * like a fresh web request.
     */
    private function configureQueue(): void
    {
        Queue::before(function (): void {
            Once::flush();
            Server::flushIdentityMap();
        });
    }

    private function configureCommands(): void
    {
        if (App::isProduction()) {
            DB::prohibitDestructiveCommands();
        }
    }

    private function configureModels(): void
    {
        // Disabled because it's causing issues with the application
        // Model::shouldBeStrict();
    }

    /**
     * One password policy for every environment (public-sector baseline): minimum
     * length from config, upper and lower case, letters, numbers and symbols. The
     * breach-corpus check needs outbound internet, so it is opt-in.
     */
    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(max(1, (int) config('security.password.min_length')))
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return config('security.password.check_breached') ? $rule->uncompromised() : $rule;
        });

        User::observe(UserPasswordObserver::class);
    }

    /**
     * Track the last successful sign-in and reset the failed-login counter for every
     * login path (password, two-factor challenge, OAuth/OIDC, invitation link).
     * Impersonation is not a sign-in of the impersonated user.
     */
    private function configureLoginTracking(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User || session('impersonating')) {
                return;
            }

            $event->user->recordSuccessfulLogin();
        });
    }

    private function configureSanctumModel(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureOidcSocialite(): void
    {
        if (! $this->app->bound(SocialiteFactory::class)) {
            return;
        }

        $this->app->make(SocialiteFactory::class)->extend('oidc', function ($app) {
            return new OidcProvider(
                $app['request'],
                $app->make(OidcDiscoveryService::class),
                $app->make(OidcTokenValidator::class),
                '',
                '',
                '',
            );
        });
    }

    private function configureGitHubHttp(): void
    {
        Http::macro('GitSource', function (string $url) {
            return Http::withOptions(SafeExternalUrl::httpClientOptions(
                $url,
                allowPrivateNetworks: SafeExternalUrl::gitSourcesMayUsePrivateNetworks(),
            ));
        });

        Http::macro('GitHub', function (string $api_url, ?string $github_access_token = null) {
            if ($github_access_token) {
                return Http::GitSource($api_url)->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => "Bearer $github_access_token",
                ])->baseUrl($api_url);
            } else {
                return Http::GitSource($api_url)->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])->baseUrl($api_url);
            }
        });
    }

    private function configureGitLabHttp(): void
    {
        Http::macro('GitLab', function (string $api_url, ?string $access_token = null) {
            $client = Http::GitSource($api_url)->withHeaders([
                'Accept' => 'application/json',
            ])->baseUrl($api_url);

            return $access_token ? $client->withToken($access_token) : $client;
        });
    }
}
