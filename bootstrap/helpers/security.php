<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('notifySecurityAlert')) {
    /**
     * Alert platform administrators about a security-relevant event.
     *
     * @param  string  $type  Short machine name, e.g. `failed_login_burst`.
     * @param  string  $title  Human readable headline.
     * @param  array<string, mixed>  $context  Non-secret details for the alert.
     */
    function notifySecurityAlert(string $type, string $title, array $context = []): void
    {
        try {
            Log::warning("Security alert: {$title}", ['type' => $type] + $context);
        } catch (Throwable) {
            // Alerting must never break the action that triggered it.
        }
    }
}
