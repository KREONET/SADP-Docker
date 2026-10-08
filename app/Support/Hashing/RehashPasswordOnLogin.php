<?php

namespace App\Support\Hashing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Upgrades a user's stored password hash (e.g. legacy bcrypt to PBKDF2) after a
 * successful password sign-in.
 *
 * Fortify only rehashes in its default credential check; a custom
 * Fortify::authenticateUsing() callback bypasses that, so the callback is wrapped here.
 */
class RehashPasswordOnLogin
{
    /**
     * Wrap the registered Fortify authentication callback so successful logins rehash.
     */
    public static function wrapFortifyCallback(): void
    {
        $callback = Fortify::$authenticateUsingCallback;
        if ($callback === null) {
            return;
        }

        Fortify::authenticateUsing(function (Request $request) use ($callback) {
            $user = $callback($request);

            if ($user instanceof Authenticatable && is_string($request->input('password'))) {
                app(self::class)->handle($user, $request->input('password'));
            }

            return $user;
        });
    }

    /**
     * Rehash the password when the current hash is outdated and the password matches it.
     */
    public function handle(Authenticatable $user, #[\SensitiveParameter] string $password): bool
    {
        if (! config('hashing.rehash_on_login', true) || ! $user instanceof Model) {
            return false;
        }

        $currentHash = $user->getAuthPassword();
        if (! is_string($currentHash) || $currentHash === '' || ! Hash::needsRehash($currentHash)) {
            return false;
        }

        if (! Hash::check($password, $currentHash)) {
            return false;
        }

        // A rehash is not a password change: skip model events so password age,
        // history and change notifications are left untouched.
        $user->forceFill([$user->getAuthPasswordName() => Hash::make($password)])->saveQuietly();

        return true;
    }
}
