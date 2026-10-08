<?php

namespace App\Services\Logging;

/**
 * Masks personal data and credentials in free-text log messages before they are stored or shipped.
 *
 * Covers e-mail addresses, `password=`/`secret=`/`token=`-style values, Bearer tokens, credentials
 * in URLs and private key blocks. Structured audit fields (such as the actor e-mail) are not passed
 * through here; they are needed to tell who did something.
 *
 * [한국어] 로그 메시지에 섞여 들어온 개인정보(이메일)와 비밀값(비밀번호, 토큰, 개인키)을 가립니다.
 * 시스템 로그 저장, 컨테이너 로그 저장, Loki 전송 직전에 같은 규칙으로 사용합니다.
 */
class SensitiveDataMasker
{
    public const REDACTED = '[REDACTED]';

    public const REDACTED_PRIVATE_KEY = '[REDACTED PRIVATE KEY]';

    private const PRIVATE_KEY_BLOCK = '/-----BEGIN ([A-Z0-9 ]*)PRIVATE KEY-----.*?(?:-----END \1PRIVATE KEY-----|$)/s';

    private const PRIVATE_KEY_BEGIN = '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/';

    private const PRIVATE_KEY_END = '/-----END [A-Z0-9 ]*PRIVATE KEY-----/';

    private const BEARER_TOKEN = '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/i';

    private const URL_CREDENTIALS = '/\b([a-z][a-z0-9+.\-]*:\/\/)([^\s:@\/]+):([^\s@\/]+)@/i';

    private const SECRET_ASSIGNMENT = '/(["\']?[\w.\-]*(?:password|passwd|secret|token|api[_\-]?key|access[_\-]?key|private[_\-]?key)[\w.\-]*["\']?)(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;"\'}\]]+)/i';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/';

    public static function mask(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $masked = preg_replace(self::PRIVATE_KEY_BLOCK, self::REDACTED_PRIVATE_KEY, $text) ?? $text;
        $masked = preg_replace(self::BEARER_TOKEN, '$1 '.self::REDACTED, $masked) ?? $masked;
        $masked = preg_replace(self::URL_CREDENTIALS, '$1$2:'.self::REDACTED.'@', $masked) ?? $masked;
        $masked = preg_replace_callback(
            self::SECRET_ASSIGNMENT,
            fn (array $matches): string => $matches[1].$matches[2].self::quoteLike($matches[3], self::REDACTED),
            $masked,
        ) ?? $masked;

        return preg_replace_callback(self::EMAIL, fn (array $matches): string => self::maskEmail($matches[0]), $masked) ?? $masked;
    }

    /**
     * Masks consecutive lines of one stream; key blocks span several lines in container output.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function maskLines(array $lines): array
    {
        $insidePrivateKey = false;
        $masked = [];

        foreach ($lines as $line) {
            if ($insidePrivateKey) {
                if (preg_match(self::PRIVATE_KEY_END, $line)) {
                    $insidePrivateKey = false;
                }
                $masked[] = self::REDACTED_PRIVATE_KEY;

                continue;
            }

            if (preg_match(self::PRIVATE_KEY_BEGIN, $line) && ! preg_match(self::PRIVATE_KEY_END, $line)) {
                $insidePrivateKey = true;
            }

            $masked[] = self::mask($line);
        }

        return $masked;
    }

    /**
     * Masks every string inside a nested array (log context, audit metadata).
     */
    public static function maskValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::mask($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::maskValue($item), $value);
        }

        return $value;
    }

    /**
     * Keeps the first character and the domain: `jane@example.com` becomes `j***@example.com`.
     */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email === '' ? $email : mb_substr($email, 0, 1).'***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        return ($local === '' ? '' : mb_substr($local, 0, 1)).'***@'.$domain;
    }

    private static function quoteLike(string $original, string $replacement): string
    {
        $quote = $original[0] ?? '';

        return in_array($quote, ['"', "'"], true) ? $quote.$replacement.$quote : $replacement;
    }
}
