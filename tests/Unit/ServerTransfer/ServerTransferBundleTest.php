<?php

use App\Services\ServerTransfer\ServerTransferBundle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('wrap adds schema version export id and timestamp', function () {
    $bundle = ServerTransferBundle::wrap(['server' => ['uuid' => 'abc']]);

    expect($bundle['schema_version'])->toBe(ServerTransferBundle::SCHEMA_VERSION)
        ->and($bundle['export_id'])->toBeString()->not->toBeEmpty()
        ->and($bundle['exported_at'])->toBeString()
        ->and($bundle['server']['uuid'])->toBe('abc');
});

test('validate rejects missing required fields', function () {
    $result = ServerTransferBundle::validate([]);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('validate accepts a minimal valid bundle', function () {
    $bundle = ServerTransferBundle::wrap([
        'private_key' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----"],
        'server' => [
            'uuid' => 'srv-1',
            'name' => 'web',
            'ip' => '10.0.0.1',
            'port' => 22,
            'user' => 'root',
        ],
        'destinations' => [],
        'projects' => [],
    ]);

    $result = ServerTransferBundle::validate($bundle);

    expect($result['valid'])->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty(); // empty destinations warning
});

test('assertValid throws validation exception', function () {
    ServerTransferBundle::assertValid(['schema_version' => 99]);
})->throws(ValidationException::class);

function bundleTestMinimalBundle(): array
{
    return ServerTransferBundle::wrap([
        'private_key' => ['private_key' => 'x'],
        'server' => ['uuid' => 's', 'name' => 'n', 'ip' => '1.1.1.1', 'port' => 22, 'user' => 'root'],
        'destinations' => [],
        'projects' => [],
    ]);
}

test('validate rejects a bundle from a newer or unknown Coolify version', function (?string $version, string $error) {
    $bundle = bundleTestMinimalBundle();
    $bundle['coolify_version'] = $version;

    $result = ServerTransferBundle::validate($bundle);

    expect($result['valid'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain($error);
})->with([
    'newer' => ['99.0.0', 'Upgrade this instance'],
    'missing' => [null, 'Missing coolify_version'],
]);

test('validate accepts a bundle from an older Coolify version', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['coolify_version'] = '4.0.0';

    expect(ServerTransferBundle::validate($bundle)['valid'])->toBeTrue();
});

function minimalTransferBundle(): array
{
    return ServerTransferBundle::wrap([
        'private_key' => ['private_key' => 'secret-key-material'],
        'server' => ['uuid' => 's', 'name' => 'n', 'ip' => '1.1.1.1', 'port' => 22, 'user' => 'root'],
        'destinations' => [],
        'projects' => [],
    ]);
}

/**
 * One v2 envelope shared by the tamper tests, because each encryption runs the full KDF.
 *
 * @return array<string, mixed>
 */
function sharedV2Envelope(): array
{
    static $envelope = null;

    return $envelope ??= ServerTransferBundle::encryptWithPassphrase(minimalTransferBundle(), 'good-pass');
}

/**
 * Builds a bundle in the legacy v1 envelope (unsalted SHA-256 key shared by AES and HMAC).
 *
 * @param  array<string, mixed>  $bundle
 * @return array<string, mixed>
 */
function legacyV1Envelope(array $bundle, string $passphrase): array
{
    $key = hash('sha256', $passphrase, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt(json_encode($bundle), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return [
        'encrypted' => true,
        'schema_version' => 1,
        'payload' => base64_encode($iv.hash_hmac('sha256', $iv.$cipher, $key).$cipher),
    ];
}

test('new encrypted bundles use the v2 pbkdf2 envelope', function () {
    config()->set('security.password.pbkdf2_iterations', 1000);

    $first = ServerTransferBundle::encryptWithPassphrase(minimalTransferBundle(), 'good-pass');
    $second = sharedV2Envelope();

    expect($first['encryption_version'])->toBe(2)
        ->and($first['kdf'])->toBe('pbkdf2-sha256')
        ->and($first['cipher'])->toBe('aes-256-cbc')
        ->and($first['iterations'])->toBeGreaterThanOrEqual(600000)
        ->and(strlen(base64_decode($first['salt'], true)))->toBeGreaterThanOrEqual(16)
        ->and(strlen(base64_decode($first['iv'], true)))->toBe(16)
        ->and($first['mac'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($first['salt'])->not->toBe($second['salt'])
        ->and($first['iv'])->not->toBe($second['iv'])
        ->and($first['payload'])->not->toBe($second['payload']);
});

test('v2 envelope does not reuse the passphrase hash as key', function () {
    $encrypted = sharedV2Envelope();

    $legacyKey = hash('sha256', 'good-pass', true);
    $decryptedWithLegacyKey = openssl_decrypt(
        base64_decode($encrypted['payload']),
        'AES-256-CBC',
        $legacyKey,
        OPENSSL_RAW_DATA,
        base64_decode($encrypted['iv'])
    );

    expect(is_string($decryptedWithLegacyKey) && str_contains($decryptedWithLegacyKey, 'secret-key-material'))->toBeFalse();
});

test('tampering with any authenticated v2 field fails before decryption', function (string $field, Closure $tamper) {
    $encrypted = sharedV2Envelope();
    $encrypted[$field] = $tamper($encrypted[$field]);

    ServerTransferBundle::decryptWithPassphrase($encrypted, 'good-pass');
})->with([
    'salt' => ['salt', fn (string $value) => base64_encode(random_bytes(32))],
    'iv' => ['iv', fn (string $value) => base64_encode(random_bytes(16))],
    'iterations' => ['iterations', fn (int $value) => $value + 1],
    'payload' => ['payload', fn (string $value) => base64_encode(strrev(base64_decode($value)))],
    'mac' => ['mac', fn (string $value) => str_repeat('0', 64)],
])->throws(RuntimeException::class);

test('v2 envelope with an unsupported version, kdf or weak iteration count is rejected', function (array $override) {
    $encrypted = array_merge(sharedV2Envelope(), $override);

    ServerTransferBundle::decryptWithPassphrase($encrypted, 'good-pass');
})->with([
    'version' => [['encryption_version' => 3]],
    'kdf' => [['kdf' => 'sha256']],
    'iterations below minimum' => [['iterations' => 1000]],
    'iterations above maximum' => [['iterations' => 100_000_000]],
])->throws(RuntimeException::class);

test('legacy v1 bundles can still be decrypted', function () {
    $original = minimalTransferBundle();

    $restored = ServerTransferBundle::decryptWithPassphrase(legacyV1Envelope($original, 'old-pass'), 'old-pass');

    expect($restored['export_id'])->toBe($original['export_id'])
        ->and($restored['private_key']['private_key'])->toBe('secret-key-material');
});

test('legacy v1 bundles still reject a wrong passphrase', function () {
    ServerTransferBundle::decryptWithPassphrase(legacyV1Envelope(minimalTransferBundle(), 'old-pass'), 'bad-pass');
})->throws(RuntimeException::class);

test('validate accepts Coolify ids and legacy RFC 4122 uuids', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['server']['uuid'] = new_public_id();
    $bundle['private_key']['uuid'] = (string) Str::uuid();
    $bundle['volume_backups'] = [['uuid' => 'abc_DEF-123', 's3_storage_uuids' => [(string) Str::uuid()], 'frequency' => 'daily']];

    expect(ServerTransferBundle::validate($bundle)['errors'])->toBe([]);
});

test('validate rejects an unsafe id in an id list', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['volume_backups'] = [['s3_storage_uuids' => ['ok', '../../x']]];

    expect(ServerTransferBundle::validate($bundle)['errors'])->toContain('volume_backups.0.s3_storage_uuids.1 is not a valid id. An id may only contain letters, digits, hyphens, and underscores (at most 64 characters).');
});

function bundleTestWithDatabasePorts(array $attributes): array
{
    $bundle = bundleTestMinimalBundle();
    $bundle['projects'] = [['environments' => [['databases' => [['attributes' => $attributes]]]]]];

    return $bundle;
}

test('validate accepts the port values that older databases have', function (array $attributes) {
    expect(ServerTransferBundle::validate(bundleTestWithDatabasePorts($attributes))['errors'])->toBe([]);
})->with([
    'no ports' => [['ports_mappings' => null, 'public_port' => null]],
    'empty mappings' => [['ports_mappings' => '']],
    'mapping list' => [['ports_mappings' => '5432:5432,8080:80']],
    'spaces and a trailing comma' => [['ports_mappings' => '5432:5432, 8080:80,']],
    'container port only' => [['ports_mappings' => '5432']],
    'ip, range and protocol' => [['ports_mappings' => '127.0.0.1:8000-8010:8000-8010/udp,[::1]::80']],
    'public port below 1024' => [['public_port' => 543]],
    'public port as digit string' => [['public_port' => '5432']],
]);

test('validate rejects garbage database port values', function (array $attributes, string $field) {
    $errors = ServerTransferBundle::validate(bundleTestWithDatabasePorts($attributes))['errors'];

    expect(implode(' ', $errors))->toContain("projects.0.environments.0.databases.0.{$field}");
})->with([
    'shell in mappings' => [['ports_mappings' => '5432:5432; rm -rf /'], 'ports_mappings'],
    'yaml in mappings' => [['ports_mappings' => "5432:5432\nprivileged: true"], 'ports_mappings'],
    'letters in mappings' => [['ports_mappings' => 'abc:def'], 'ports_mappings'],
    'mappings as array' => [['ports_mappings' => ['5432:5432']], 'ports_mappings'],
    'public port zero' => [['public_port' => 0], 'public_port'],
    'public port too high' => [['public_port' => 70000], 'public_port'],
    'public port text' => [['public_port' => '5432; id'], 'public_port'],
]);
