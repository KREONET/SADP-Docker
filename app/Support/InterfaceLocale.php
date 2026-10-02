<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Picks the interface language of a request: the user's own choice, then the instance
 * default set by a platform admin. Before sign-in the browser language is used, so the
 * login page already shows the language the visitor reads.
 */
class InterfaceLocale
{
    public const FALLBACK = 'en';

    /**
     * @return array<string, string> code => native name
     */
    public static function available(): array
    {
        return config('app.available_locales', [self::FALLBACK => 'English']);
    }

    public static function isAvailable(?string $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::available());
    }

    public static function instanceDefault(): string
    {
        try {
            $locale = instanceSettings()->default_locale;
        } catch (Throwable) {
            $locale = null;
        }

        return self::isAvailable($locale) ? $locale : self::FALLBACK;
    }

    public static function forRequest(Request $request, ?User $user): string
    {
        if ($user !== null) {
            return self::isAvailable($user->locale) ? $user->locale : self::instanceDefault();
        }

        return self::fromBrowser($request) ?? self::instanceDefault();
    }

    /**
     * The first Accept-Language entry with an available language, matched by primary subtag (ko-KR → ko).
     * Symfony's getPreferredLanguage() returns the first available language when nothing matches,
     * which would hide the instance default.
     */
    private static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower((string) preg_split('/[-_]/', $language)[0]);
            if (self::isAvailable($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
