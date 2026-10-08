<?php

use App\Services\Logging\SensitiveDataMasker;

it('keeps the first character and the domain of an e-mail address', function (string $email, string $masked) {
    expect(SensitiveDataMasker::maskEmail($email))->toBe($masked);
})->with([
    ['jane@example.com', 'j***@example.com'],
    ['j@example.com', 'j***@example.com'],
    ['not-an-email', 'n***'],
    ['', ''],
]);

it('masks e-mail addresses inside free text', function () {
    expect(SensitiveDataMasker::mask('Invitation sent to jane.doe+ops@mail.example.co.kr by admin@example.com'))
        ->toBe('Invitation sent to j***@mail.example.co.kr by a***@example.com');
});

it('masks password, secret and token assignments', function (string $input, string $expected) {
    expect(SensitiveDataMasker::mask($input))->toBe($expected);
})->with([
    'query string' => ['GET /callback?code=1&token=abc123&state=x', 'GET /callback?code=1&token=[REDACTED]&state=x'],
    'env style' => ['DB_PASSWORD=hunter2 started', 'DB_PASSWORD=[REDACTED] started'],
    'colon' => ['secret: s3cr3t', 'secret: [REDACTED]'],
    'json' => ['{"password":"hunter2","user":"jane"}', '{"password":"[REDACTED]","user":"jane"}'],
    'single quotes' => ["api_key='abc def'", "api_key='[REDACTED]'"],
    'access token' => ['access_token=ya29.a0AfH6', 'access_token=[REDACTED]'],
]);

it('masks bearer tokens', function () {
    expect(SensitiveDataMasker::mask('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig_-123=='))
        ->toBe('Authorization: Bearer [REDACTED]');
});

it('masks credentials in urls', function () {
    expect(SensitiveDataMasker::mask('cloning https://deploy:ghp_abcdef@github.com/acme/app.git'))
        ->toBe('cloning https://deploy:[REDACTED]@github.com/acme/app.git');
});

it('masks private key blocks', function () {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nAAAAAAAA\n-----END OPENSSH PRIVATE KEY-----";

    expect(SensitiveDataMasker::mask("key loaded: {$key} done"))->toBe('key loaded: [REDACTED PRIVATE KEY] done')
        ->and(SensitiveDataMasker::mask("-----BEGIN RSA PRIVATE KEY-----\nMIIEow"))->toBe('[REDACTED PRIVATE KEY]');
});

it('masks private key blocks that span several log lines', function () {
    expect(SensitiveDataMasker::maskLines([
        'starting',
        '-----BEGIN RSA PRIVATE KEY-----',
        'MIIEowIBAAKCAQEA',
        '-----END RSA PRIVATE KEY-----',
        'ready for jane@example.com',
    ]))->toBe([
        'starting',
        '[REDACTED PRIVATE KEY]',
        '[REDACTED PRIVATE KEY]',
        '[REDACTED PRIVATE KEY]',
        'ready for j***@example.com',
    ]);
});

it('leaves ordinary log lines untouched', function (string $line) {
    expect(SensitiveDataMasker::mask($line))->toBe($line);
})->with([
    'GET /health 200 3ms',
    'Password reset link requested',
    'Listening on 0.0.0.0:3000',
    '',
]);

it('masks every string in nested values', function () {
    expect(SensitiveDataMasker::maskValue(['a' => 'mail jane@example.com', 'b' => ['c' => 'token=abc'], 'd' => 5, 'e' => null]))
        ->toBe(['a' => 'mail j***@example.com', 'b' => ['c' => 'token=[REDACTED]'], 'd' => 5, 'e' => null]);
});
