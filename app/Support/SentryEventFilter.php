<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;
use Throwable;

/**
 * Last line of defence for Sentry: drops every event unless the instance
 * administrator opted in to error reporting, and never sends user identity.
 *
 * Referenced from config/sentry.php as a static callable so it survives config caching.
 */
class SentryEventFilter
{
    /**
     * Whether the instance administrator enabled error reporting.
     * Any failure to read the setting (e.g. database unavailable) denies reporting.
     */
    public static function reportingEnabled(): bool
    {
        try {
            return (bool) instanceSettings()->is_error_reporting_enabled;
        } catch (Throwable) {
            return false;
        }
    }

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        if (! self::reportingEnabled()) {
            return null;
        }

        return self::stripUserData($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::beforeSend($event, $hint);
    }

    /**
     * Remove user identity (email, IP, username) from the event.
     */
    public static function stripUserData(Event $event): Event
    {
        $event->setUser(null);

        return $event;
    }
}
