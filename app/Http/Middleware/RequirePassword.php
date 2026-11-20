<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword as Middleware;

class RequirePassword extends Middleware
{
    /**
     * Account security settings require Fortify's confirmation. An SSO user
     * without a local password can set their first one on the confirmation page.
     */
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null)
    {
        if (! $request->routeIs('two-factor.*', 'passkey.*', 'profile.passkeys.confirm')
            && $request->user()?->requiresPasswordConfirmation() === false) {
            return false;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
