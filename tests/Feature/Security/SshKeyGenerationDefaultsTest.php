<?php

use App\Enums\SshKeyType;
use App\Livewire\Security\PrivateKey\Index;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    Config::set('cache.default', 'array');
    Storage::fake('ssh-keys');
});

test('generated keys default to ECDSA P-256 in OpenSSH format', function () {
    $key = generateSSHKey();

    $loaded = PublicKeyLoader::load($key['private']);

    expect(SshKeyType::default())->toBe(SshKeyType::ECDSA)
        ->and($key['private'])->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($key['public'])->toStartWith('ecdsa-sha2-nistp256 ')
        ->and($loaded)->toBeInstanceOf(EC\PrivateKey::class)
        ->and($loaded->getCurve())->toBe('secp256r1');
});

test('generated RSA keys are 3072-bit', function () {
    $key = generateSSHKey('rsa');

    $loaded = PublicKeyLoader::load($key['private']);

    expect($loaded)->toBeInstanceOf(RSA\PrivateKey::class)
        ->and($loaded->getLength())->toBe(3072)
        ->and($key['public'])->toStartWith('ssh-rsa ');
});

test('ed25519 stays available but is not the default or approved', function () {
    $key = generateSSHKey('ed25519');

    expect($key['public'])->toStartWith('ssh-ed25519 ')
        ->and(SshKeyType::ED25519->isApproved())->toBeFalse()
        ->and(SshKeyType::ECDSA->isApproved())->toBeTrue()
        ->and(SshKeyType::RSA->isApproved())->toBeTrue();
});

test('unknown key types are rejected', function () {
    generateSSHKey('dsa');
})->throws(Exception::class, 'Invalid key type');

test('generated keys work with public key extraction and fingerprints', function (string $type) {
    $key = generateSSHKey($type);

    $publicKey = PrivateKey::extractPublicKeyFromPrivate($key['private']);

    expect(PrivateKey::validatePrivateKey($key['private']))->toBeTrue()
        ->and($publicKey)->toBe(trim(str_replace('coolify-generated-ssh-key', '', $key['public'])).' ')
        ->and(PrivateKey::generateFingerprint($key['private']))->toBeString()->not->toBeEmpty()
        ->and(PrivateKey::generateMd5Fingerprint($key['private']))->toMatch('/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/');
})->with(['ecdsa', 'rsa', 'ed25519']);

test('private key menu lists ECDSA first and marks ED25519 as not approved', function () {
    Livewire::test(Index::class)
        ->assertSeeInOrder([
            'Generate ECDSA P-256 (recommended)',
            'Generate RSA 3072',
            'Generate ED25519 (not KCMVP-approved)',
        ])
        ->assertSee("generatePrivateKey('ecdsa')", false);
});

test('private key index accepts the ecdsa type and rejects unknown types', function () {
    Livewire::test(Index::class)
        ->call('generatePrivateKey', 'ecdsa')
        ->assertDispatched('success');

    expect(PrivateKey::query()->count())->toBe(1);

    Livewire::test(Index::class)
        ->call('generatePrivateKey', 'dsa')
        ->assertDispatched('error');

    expect(PrivateKey::query()->count())->toBe(1);
});
