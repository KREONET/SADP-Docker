<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;

/**
 * Literal translation keys used by the interface: __('…'), trans('…'), trans_choice('…') and @lang('…').
 *
 * @return array<string, list<string>> key => files
 */
function interfaceTranslationKeys(): array
{
    $files = Finder::create()->files()->in([base_path('resources/views'), base_path('app'), base_path('bootstrap/helpers')])
        ->name(['*.php'])
        ->exclude(['emails', 'vendor/mail']);

    $pattern = '/(?:\b__|\btrans_choice|\btrans|@lang)\(\s*(?:\'((?:\\\\.|[^\'\\\\])*)\'|"((?:\\\\.|[^"\\\\$])*)")\s*[,)]/s';
    $keys = [];
    foreach ($files as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = isset($match[2]) && $match[2] !== ''
                ? stripcslashes($match[2])
                : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
            if ($key !== '') {
                $keys[$key][] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }
    ksort($keys);

    return $keys;
}

/**
 * @return list<string>
 */
function translationPlaceholders(string $text): array
{
    preg_match_all('/(?<![\w:]):([a-z_][a-z0-9_]*)/i', $text, $matches);
    $placeholders = array_map('strtolower', array_unique($matches[1]));
    sort($placeholders);

    return $placeholders;
}

test('every interface string has a korean translation', function () {
    $missing = collect(interfaceTranslationKeys())
        ->reject(fn (array $files, string $key): bool => Lang::hasForLocale($key, 'ko'))
        ->map(fn (array $files, string $key): string => $key.'  ('.implode(', ', array_unique($files)).')')
        ->values();

    expect($missing->all())->toBe([], 'Add these keys to lang/ko.json:');
});

test('korean translations keep the placeholders of the source text', function () {
    $english = json_decode(file_get_contents(base_path('lang/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $korean = json_decode(file_get_contents(base_path('lang/ko.json')), true, flags: JSON_THROW_ON_ERROR);

    $mismatched = collect($korean)
        ->filter(fn (string $translation, string $key): bool => translationPlaceholders($english[$key] ?? $key) !== translationPlaceholders($translation))
        ->keys();

    expect($mismatched->all())->toBe([]);
});

test('korean translations are not empty', function () {
    $korean = json_decode(file_get_contents(base_path('lang/ko.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($korean)->filter(fn (mixed $value): bool => ! is_string($value) || trim($value) === '')->keys()->all())->toBe([]);
});

test('the korean laravel messages cover every english validation rule', function () {
    $english = Lang::get('validation', [], 'en');
    $korean = require base_path('lang/ko/validation.php');

    expect(array_diff(array_keys(array_filter($english, 'is_string')), array_keys($korean)))->toBe([]);
    foreach (array_filter($english, 'is_array') as $rule => $variants) {
        if (in_array($rule, ['custom', 'attributes'], true)) {
            continue;
        }
        expect(array_keys($korean[$rule] ?? []))->toEqualCanonicalizing(array_keys($variants));
    }
});

test('translated interface views compile to valid php', function () {
    $files = Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')
        ->exclude(['emails', 'vendor/mail']);
    $errors = [];

    foreach ($files as $file) {
        try {
            token_get_all(Blade::compileString($file->getContents()), TOKEN_PARSE);
        } catch (Throwable $exception) {
            $errors[] = $file->getRelativePathname().': '.$exception->getMessage();
        }
    }

    expect($errors)->toBe([]);
});
