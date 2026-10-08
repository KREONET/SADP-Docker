<?php

/*
|--------------------------------------------------------------------------
| Security policy
|--------------------------------------------------------------------------
|
| Thresholds for the public-sector security baseline (국가정보보안기본지침,
| 행안부 SW 개발보안 가이드, FIPS 140-3). Every value can be tightened per
| installation through the environment.
|
*/

return [

    'password' => [
        'min_length' => (int) env('SECURITY_PASSWORD_MIN_LENGTH', 9),
        // Days before a local password must be changed; 0 disables expiry.
        'expires_after_days' => (int) env('SECURITY_PASSWORD_EXPIRES_AFTER_DAYS', 90),
        // Number of previous passwords that may not be reused.
        'history' => (int) env('SECURITY_PASSWORD_HISTORY', 5),
        // Checks passwords against a public breach corpus; needs outbound internet.
        'check_breached' => (bool) env('SECURITY_PASSWORD_CHECK_BREACHED', false),
        'pbkdf2_iterations' => (int) env('SECURITY_PBKDF2_ITERATIONS', 600000),
    ],

    'lockout' => [
        'max_attempts' => (int) env('SECURITY_LOCKOUT_MAX_ATTEMPTS', 5),
        'minutes' => (int) env('SECURITY_LOCKOUT_MINUTES', 30),
    ],

    'session' => [
        'idle_timeout_minutes' => (int) env('SECURITY_SESSION_IDLE_MINUTES', 30),
        'absolute_timeout_minutes' => (int) env('SECURITY_SESSION_ABSOLUTE_MINUTES', 720),
        // Allow only one active session per user.
        'single_session' => (bool) env('SECURITY_SINGLE_SESSION', false),
    ],

    'two_factor' => [
        // none, admins (platform administrators) or all.
        'required_for' => env('SECURITY_REQUIRE_TWO_FACTOR', 'admins'),
    ],

    'accounts' => [
        // Days without a login before an account is deactivated; 0 disables.
        'deactivate_inactive_after_days' => (int) env('SECURITY_DEACTIVATE_INACTIVE_AFTER_DAYS', 90),
    ],

    'api_tokens' => [
        'max_lifetime_days' => (int) env('SECURITY_API_TOKEN_MAX_DAYS', 90),
    ],

    'audit' => [
        'retention_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 365),
        'min_retention_days' => 365,
        'system_log_min_retention_days' => (int) env('SECURITY_SYSTEM_LOG_MIN_RETENTION_DAYS', 180),
        // Failed logins within the window that trigger a security alert.
        'failed_login_alert_threshold' => (int) env('SECURITY_FAILED_LOGIN_ALERT_THRESHOLD', 10),
        'failed_login_alert_window_minutes' => (int) env('SECURITY_FAILED_LOGIN_ALERT_WINDOW_MINUTES', 10),
    ],

];
