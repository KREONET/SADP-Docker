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

    'reauthentication' => [
        // Minutes a successful identity confirmation unlocks sensitive reads.
        'window_minutes' => (int) env('SECURITY_REAUTH_WINDOW_MINUTES', 15),
        'max_attempts' => (int) env('SECURITY_REAUTH_MAX_ATTEMPTS', 5),
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

    'hsts' => [
        'max_age_seconds' => (int) env('SECURITY_HSTS_MAX_AGE_SECONDS', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    'proxy' => [
        // HTTP/3 (QUIC on UDP 443) on the generated Traefik and Caddy proxies. Off by default: QUIC traffic
        // bypasses most TCP-based inspection, so only turn it on where the network policy allows it.
        'http3' => (bool) env('SECURITY_PROXY_HTTP3', false),
    ],

    'admin_ip' => [
        // Proxies whose X-Forwarded-For entries the administrator IP allowlist trusts (comma separated IPs/CIDRs),
        // e.g. the address of the Coolify proxy container. Empty trusts every private address.
        'trusted_proxies' => env('SECURITY_ADMIN_IP_TRUSTED_PROXIES', ''),
    ],

    'ssh' => [
        // Per-server known_hosts files (trust on first use). Managed by App\Services\Ssh\KnownHosts.
        'known_hosts_directory' => storage_path('app/ssh/known_hosts'),
        // known_hosts file for git hosts. Git runs on the build server (or in its helper
        // container), so this path lives in the Coolify data directory on that server.
        'git_known_hosts_file' => env('SECURITY_SSH_GIT_KNOWN_HOSTS_FILE', '/data/coolify/ssh/git/known_hosts'),
        // FIPS 140-3 approved algorithms. Comma-separated OpenSSH lists; an empty value keeps the OpenSSH default.
        'ciphers' => env('SECURITY_SSH_CIPHERS', 'aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr'),
        'kex_algorithms' => env('SECURITY_SSH_KEX_ALGORITHMS', 'ecdh-sha2-nistp521,ecdh-sha2-nistp384,ecdh-sha2-nistp256,diffie-hellman-group16-sha512,diffie-hellman-group14-sha256'),
        'macs' => env('SECURITY_SSH_MACS', 'hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256'),
        'host_key_algorithms' => env('SECURITY_SSH_HOST_KEY_ALGORITHMS', 'ecdsa-sha2-nistp521,ecdsa-sha2-nistp384,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256'),
        // Appends ssh-ed25519 to the host key algorithms. Ed25519 is not FIPS 140-3 approved for
        // every module, but many servers only have an Ed25519 host key, so it is allowed by default.
        // Set to false on fully FIPS-validated estates once every server has an ECDSA or RSA host key.
        'allow_ed25519_host_keys' => (bool) env('SECURITY_SSH_ALLOW_ED25519_HOST_KEYS', true),
        // Minutes between repeated alerts and audit events for the same server whose host key changed.
        'host_key_changed_alert_throttle_minutes' => (int) env('SECURITY_SSH_HOST_KEY_ALERT_THROTTLE_MINUTES', 60),
    ],

];
