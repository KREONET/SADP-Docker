<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiSensitiveData
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()->currentAccessToken();
        $hasTokenPermission = $token->can('root') || $token->can('read:sensitive');
        $teamId = data_get($token, 'team_id');
        // team_id 0 is the instance-admin team, so a falsy check must not exclude it
        $isAdmin = ! is_null($teamId) ? $request->user()->isAdminOfTeam((int) $teamId) : false;
        $canReadSensitive = $hasTokenPermission && $isAdmin;

        // Allow access to sensitive data only if token has permission AND user is admin/owner
        $request->attributes->add([
            'can_read_sensitive' => $canReadSensitive,
        ]);

        $response = $next($request);

        if ($canReadSensitive) {
            $this->auditSensitiveRead($request, $response, $token);
        }

        return $response;
    }

    /**
     * One audit row per successful read served with sensitive fields (never per list item, never values).
     */
    private function auditSensitiveRead(Request $request, Response $response, mixed $token): void
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return;
        }

        auditLog('api.sensitive_data.read', [
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'token_id' => data_get($token, 'id'),
            'token_name' => data_get($token, 'name'),
            'team_id' => data_get($token, 'team_id'),
            'token_ability' => $token->can('root') ? 'root' : 'read:sensitive',
            'result' => 'success',
        ]);
    }
}
