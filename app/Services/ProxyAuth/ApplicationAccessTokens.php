<?php

namespace App\Services\ProxyAuth;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tokens for applications in "signed-in users only" mode.
 *
 * A one-time code carries a signed-in panel user to the application's own domain, where it is
 * exchanged for a session cookie bound to that application and host. The cookie is an HMAC-signed
 * value, so the verify endpoint needs no database row or shared session for it.
 */
class ApplicationAccessTokens
{
    public const COOKIE_NAME = '__paded_app_session';

    public const SESSION_TTL_SECONDS = 43200;

    private const CODE_TTL_SECONDS = 60;

    public function issueCode(User $user, Application $application, string $host, string $returnPath): string
    {
        $code = Str::random(48);

        Cache::put($this->codeKey($code), [
            'user_id' => $user->id,
            'application_uuid' => $application->uuid,
            'host' => strtolower($host),
            'return_path' => $returnPath,
        ], now()->addSeconds(self::CODE_TTL_SECONDS));

        return $code;
    }

    /**
     * Redeems a code once, for the application and host it was issued for.
     *
     * @return array{user_id: int, return_path: string}|null
     */
    public function redeemCode(string $code, Application $application, string $host): ?array
    {
        if ($code === '' || strlen($code) > 128) {
            return null;
        }

        $payload = Cache::lock($this->codeKey($code).':lock', 5)
            ->get(fn () => Cache::pull($this->codeKey($code)));

        if (! is_array($payload)
            || data_get($payload, 'application_uuid') !== $application->uuid
            || data_get($payload, 'host') !== strtolower($host)) {
            return null;
        }

        return [
            'user_id' => (int) $payload['user_id'],
            'return_path' => (string) $payload['return_path'],
        ];
    }

    public function issueSession(int $userId, Application $application, string $host): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'u' => $userId,
            'a' => $application->uuid,
            'h' => strtolower($host),
            'e' => now()->addSeconds(self::SESSION_TTL_SECONDS)->timestamp,
        ])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * The user a session cookie belongs to, when it is valid for this application and host.
     */
    public function userFromSession(?string $token, Application $application, string $host): ?User
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);
        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data)
            || data_get($data, 'a') !== $application->uuid
            || data_get($data, 'h') !== strtolower($host)
            || ! is_int(data_get($data, 'e'))
            || $data['e'] < now()->timestamp) {
            return null;
        }

        return User::query()->whereNull('deactivated_at')->find((int) data_get($data, 'u'));
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'paded-application-session|'.$payload, (string) config('app.key'));
    }

    private function codeKey(string $code): string
    {
        return 'proxy-auth-code:'.hash('sha256', $code);
    }
}
