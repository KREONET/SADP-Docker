<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends a security alert at most once per window for the same alert type.
 *
 * Failing log sinks (audit table, Loki) fail on every request or run, so alerting on each failure
 * would flood administrators.
 *
 * [한국어] 같은 종류의 보안 알림을 일정 시간(기본 10분)에 한 번만 보냅니다.
 * 감사 로그 저장 실패나 Loki 전송 실패는 매 요청마다 반복되므로 알림 폭주를 막습니다.
 */
class ThrottledSecurityAlert
{
    public const WINDOW_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $context
     * @return bool Whether the alert was sent.
     */
    public static function send(string $type, string $title, array $context = [], int $windowMinutes = self::WINDOW_MINUTES): bool
    {
        try {
            if (! Cache::add(self::cacheKey($type), true, now()->addMinutes($windowMinutes))) {
                return false;
            }
        } catch (Throwable) {
            // Without a working cache the alert is still worth sending.
        }

        notifySecurityAlert($type, $title, $context);

        return true;
    }

    public static function cacheKey(string $type): string
    {
        return "security-alert-throttle:{$type}";
    }
}
