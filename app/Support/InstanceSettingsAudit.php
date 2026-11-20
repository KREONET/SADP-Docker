<?php

namespace App\Support;

use App\Models\InstanceSettings;
use BackedEnum;
use DateTimeInterface;

/**
 * Records changes to instance settings as a single `instance.settings.updated` audit event per save.
 */
class InstanceSettingsAudit
{
    public const EVENT = 'instance.settings.updated';

    /**
     * Fields whose values are never written to the audit log; only their names are recorded.
     *
     * The public addresses are masked in the settings UI, so they are treated the same way.
     *
     * @var array<int, string>
     */
    public const SECRET_FIELDS = [
        'domain_connect_private_key',
        'smtp_password',
        'smtp_username',
        'resend_api_key',
        'sentinel_token',
        'forgejo_bot_token',
        'forgejo_webhook_secret',
        'loki_password',
        'public_ipv4',
        'public_ipv6',
    ];

    /**
     * Fields that change who can reach the instance; changing them raises a security alert.
     *
     * @var array<int, string>
     */
    public const ALERT_FIELDS = [
        'allowed_ips',
        'admin_allowed_ips',
        'is_registration_enabled',
        'is_api_enabled',
        'is_sso_only_login_enabled',
        'is_mcp_server_enabled',
    ];

    /**
     * Save the settings and audit the fields that actually changed.
     *
     * @return array<int, string> Names of the changed fields.
     */
    public static function save(InstanceSettings $settings): array
    {
        $changes = self::pendingChanges($settings);
        $settings->save();
        self::record($changes);

        return array_keys($changes);
    }

    /**
     * Capture old and new values of every dirty attribute. Call before saving.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function pendingChanges(InstanceSettings $settings): array
    {
        $changes = [];
        foreach (array_keys($settings->getDirty()) as $field) {
            $old = $settings->getOriginal($field);
            $new = $settings->getAttribute($field);
            if (self::isEquivalent($old, $new)) {
                continue;
            }
            $changes[$field] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /**
     * Columns without a boolean cast come back as 0/1 and empty text may be null or '',
     * so Eloquent reports them dirty although nothing changed for the administrator.
     */
    private static function isEquivalent(mixed $old, mixed $new): bool
    {
        if ($old === $new) {
            return true;
        }

        if (is_bool($old) || is_bool($new)) {
            $isBoolLike = fn (mixed $value): bool => is_bool($value) || $value === null || in_array($value, [0, 1, '0', '1'], true);

            return $isBoolLike($old) && $isBoolLike($new) && (bool) $old === (bool) $new;
        }

        $isEmpty = fn (mixed $value): bool => $value === null || $value === '' || $value === [];
        if ($isEmpty($old) && $isEmpty($new)) {
            return true;
        }

        if (is_numeric($old) && is_numeric($new)) {
            return (string) $old === (string) $new || (float) $old === (float) $new;
        }

        if (is_array($old) && is_array($new)) {
            return $old == $new;
        }

        return false;
    }

    /**
     * Write one audit event for the given changes and alert administrators about access-related ones.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public static function record(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $fields = array_keys($changes);
        $values = [];
        foreach ($changes as $field => $change) {
            if (self::isSecret($field)) {
                continue;
            }
            $old = self::normalize($change['old'] ?? null);
            $new = self::normalize($change['new'] ?? null);
            if (is_bool($old) !== is_bool($new) && (is_bool($old) || is_bool($new))) {
                // Columns without a boolean cast read back as 0/1; record both sides as booleans.
                $old = $old === null ? null : (bool) $old;
                $new = $new === null ? null : (bool) $new;
            }
            $values[$field] = ['old' => $old, 'new' => $new];
        }

        auditLog(self::EVENT, [
            'fields' => $fields,
            'changes' => $values,
            'result' => 'success',
        ]);

        $alertFields = array_values(array_intersect($fields, self::ALERT_FIELDS));
        if ($alertFields !== []) {
            notifySecurityAlert('instance_security_setting_changed', 'Instance security settings changed', [
                'fields' => $alertFields,
                'changes' => array_intersect_key($values, array_flip($alertFields)),
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'ip' => app()->bound('request') ? request()->ip() : null,
            ]);
        }
    }

    public static function isSecret(string $field): bool
    {
        return in_array($field, self::SECRET_FIELDS, true)
            || (bool) preg_match('/password|secret|token|private_key|api_key|credential/i', $field);
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            is_object($value) => method_exists($value, '__toString') ? (string) $value : null,
            default => $value,
        };
    }
}
