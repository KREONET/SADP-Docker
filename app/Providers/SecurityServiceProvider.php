<?php

namespace App\Providers;

use App\Support\Hashing\Pbkdf2Hasher;
use App\Support\Hashing\RehashPasswordOnLogin;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registered on resolution so the driver exists even if another provider hashes during boot.
        $this->app->extend('hash', function (HashManager $manager): HashManager {
            return $manager->extend('pbkdf2', function (): Pbkdf2Hasher {
                return new Pbkdf2Hasher((int) config('security.password.pbkdf2_iterations', 600000));
            });
        });
    }

    public function boot(): void
    {
        // Runs after every provider has booted, so FortifyServiceProvider has registered its callback.
        $this->app->booted(function (): void {
            RehashPasswordOnLogin::wrapFortifyCallback();
        });
    }
}
