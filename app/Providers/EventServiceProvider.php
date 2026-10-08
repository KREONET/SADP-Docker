<?php

namespace App\Providers;

use App\Services\Logging\SystemLogRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Throwable;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SocialiteWasCalled::class => [
            AuthentikExtendSocialite::class.'@handle',
        ],
    ];

    /**
     * Fortify two-factor events and the audit event name, result, and level each one records.
     *
     * @var array<class-string, array{0: string, 1: string, 2: string}>
     */
    private const TWO_FACTOR_AUDIT_EVENTS = [
        TwoFactorAuthenticationEnabled::class => ['auth.two_factor.enabled', 'success', 'info'],
        TwoFactorAuthenticationConfirmed::class => ['auth.two_factor.confirmed', 'success', 'info'],
        TwoFactorAuthenticationDisabled::class => ['auth.two_factor.disabled', 'success', 'warning'],
        RecoveryCodesGenerated::class => ['auth.two_factor.recovery_codes_generated', 'success', 'info'],
        RecoveryCodeReplaced::class => ['auth.two_factor.recovery_code_used', 'success', 'warning'],
        ValidTwoFactorAuthenticationCodeProvided::class => ['auth.two_factor.challenge_succeeded', 'success', 'info'],
        TwoFactorAuthenticationFailed::class => ['auth.two_factor.challenge_failed', 'failure', 'warning'],
    ];

    public function boot(): void
    {
        Event::listen(MessageLogged::class, [SystemLogRecorder::class, 'handle']);
        Event::listen(Login::class, function (Login $event): void {
            auditLog('auth.user.login_succeeded', $this->authContext($event->user) + ['result' => 'success']);
        });
        Event::listen(Failed::class, function (Failed $event): void {
            auditLog('auth.user.login_failed', [
                'attempted_email' => data_get($event->credentials, 'email'),
                'guard' => $event->guard,
                'result' => 'failure',
            ], 'warning');
            $this->trackFailedLoginBurst();
        });
        Event::listen(Logout::class, function (Logout $event): void {
            auditLog('auth.user.logged_out', $this->authContext($event->user));
        });
        Event::listen(Registered::class, function (Registered $event): void {
            auditLog('auth.user.registered', $this->authContext($event->user));
        });
        Event::listen(Verified::class, function (Verified $event): void {
            auditLog('auth.user.email_verified', $this->authContext($event->user));
        });
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            auditLog('auth.user.password_reset', $this->authContext($event->user));
        });

        foreach (self::TWO_FACTOR_AUDIT_EVENTS as $eventClass => [$auditEvent, $result, $level]) {
            Event::listen($eventClass, function (object $event) use ($auditEvent, $result, $level): void {
                auditLog($auditEvent, $this->authContext($event->user) + ['result' => $result], $level);
            });
        }
    }

    private function authContext(?object $user): array
    {
        return [
            'team_id' => $user?->currentTeam()?->id,
            'resource' => 'user',
            'user_name' => $user?->name,
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
        ];
    }

    /**
     * Count failed logins per client IP and instance-wide in a fixed window and
     * alert the platform administrators once per window when the threshold is reached.
     */
    private function trackFailedLoginBurst(): void
    {
        try {
            $threshold = (int) config('security.audit.failed_login_alert_threshold');
            $windowMinutes = max(1, (int) config('security.audit.failed_login_alert_window_minutes'));

            if ($threshold <= 0) {
                return;
            }

            $ip = (string) (request()->ip() ?? 'unknown');
            $scopes = [
                'ip:'.hash('sha256', $ip) => ['scope' => 'ip', 'ip' => $ip],
                'global' => ['scope' => 'global'],
            ];

            foreach ($scopes as $suffix => $context) {
                $key = "security:failed-logins:{$suffix}";
                Cache::add($key, 0, now()->addMinutes($windowMinutes));
                $count = (int) Cache::increment($key);

                if ($count !== $threshold) {
                    continue;
                }

                notifySecurityAlert('failed_login_burst', 'Repeated failed login attempts detected', $context + [
                    'count' => $count,
                    'window_minutes' => $windowMinutes,
                ]);
            }
        } catch (Throwable) {
            // Alerting must never break the login flow.
        }
    }

    public function shouldDiscoverEvents(): bool
    {
        return true;
    }
}
