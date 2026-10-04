<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Scim\ScimResponse;
use App\Models\OauthSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts SCIM requests from the identity provider only with the bearer token issued on the OIDC settings page.
 */
class AuthenticateScim
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OauthSetting::scimTokenMatches($request->bearerToken())) {
            return ScimResponse::error(401, 'A valid SCIM bearer token is required.')
                ->header('WWW-Authenticate', 'Bearer realm="scim"');
        }

        return $next($request);
    }
}
