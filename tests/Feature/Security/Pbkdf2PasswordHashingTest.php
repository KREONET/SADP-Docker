<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Support\Hashing\Pbkdf2Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

const LEGACY_FACTORY_BCRYPT = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => false]);
    Once::flush();
    Cache::flush();
    Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
});

function pbkdf2Iterations(string $hash): int
{
    preg_match('/\$i=(\d+)\$/', $hash, $matches);

    return (int) $matches[1];
}

it('uses the pbkdf2 driver by default with the configured iteration count', function () {
    expect(config('hashing.driver'))->toBe('pbkdf2')
        ->and(Hash::driver())->toBeInstanceOf(Pbkdf2Hasher::class);

    $hash = Hash::make('Corr3ct!Pass');

    expect($hash)->toMatch('/^\$pbkdf2-sha256\$i=\d+\$[A-Za-z0-9+\/=]+\$[A-Za-z0-9+\/=]+$/')
        ->and(pbkdf2Iterations($hash))->toBe((int) config('security.password.pbkdf2_iterations'))
        ->and(Hash::check('Corr3ct!Pass', $hash))->toBeTrue()
        ->and(Hash::check('wrong', $hash))->toBeFalse()
        ->and(Hash::needsRehash($hash))->toBeFalse()
        ->and(Hash::isHashed($hash))->toBeTrue()
        ->and(Hash::info($hash)['algoName'])->toBe('pbkdf2-sha256');
});

it('defaults to 600000 iterations, a 32-byte salt and a 32-byte PBKDF2-HMAC-SHA256 output', function () {
    $hasher = new Pbkdf2Hasher;
    $hash = $hasher->make('Corr3ct!Pass');

    [, , $iterations, $salt, $digest] = explode('$', $hash);

    expect($iterations)->toBe('i=600000')
        ->and(strlen(base64_decode($salt, true)))->toBe(32)
        ->and(base64_decode($digest, true))->toBe(hash_pbkdf2('sha256', 'Corr3ct!Pass', base64_decode($salt, true), 600000, 32, true))
        ->and($hasher->check('Corr3ct!Pass', $hash))->toBeTrue();
});

it('salts every hash', function () {
    expect(Hash::make('same-password'))->not->toBe(Hash::make('same-password'));
});

it('still verifies legacy bcrypt hashes and flags them for rehash', function () {
    $bcrypt = password_hash('Legacy!Pass1', PASSWORD_BCRYPT, ['cost' => 4]);

    expect(Hash::check('Legacy!Pass1', $bcrypt))->toBeTrue()
        ->and(Hash::check('wrong', $bcrypt))->toBeFalse()
        ->and(Hash::needsRehash($bcrypt))->toBeTrue()
        ->and(Hash::check('password', LEGACY_FACTORY_BCRYPT))->toBeTrue();
});

it('flags pbkdf2 hashes with fewer iterations than configured for rehash', function () {
    $weaker = (new Pbkdf2Hasher(1000))->make('Corr3ct!Pass');

    expect((new Pbkdf2Hasher(2000))->needsRehash($weaker))->toBeTrue()
        ->and((new Pbkdf2Hasher(1000))->needsRehash($weaker))->toBeFalse()
        ->and((new Pbkdf2Hasher(2000))->check('Corr3ct!Pass', $weaker))->toBeTrue();
});

it('rejects malformed, tampered, unknown and plaintext values', function (string $stored) {
    expect(Hash::check('Corr3ct!Pass', $stored))->toBeFalse();
})->with([
    'plaintext' => 'Corr3ct!Pass',
    'empty' => '',
    'md5' => md5('Corr3ct!Pass'),
    'argon2id' => fn () => password_hash('Corr3ct!Pass', PASSWORD_ARGON2ID),
    'truncated pbkdf2' => fn () => substr(Hash::make('Corr3ct!Pass'), 0, -8),
    'tampered digest' => function () {
        $parts = explode('$', Hash::make('Corr3ct!Pass'));
        $parts[4] = base64_encode(random_bytes(32));

        return implode('$', $parts);
    },
    'excessive iterations' => function () {
        $parts = explode('$', Hash::make('Corr3ct!Pass'));
        $parts[2] = 'i=999999999';

        return implode('$', $parts);
    },
]);

it('enforces the minimum iteration count and refuses absurd configuration', function () {
    expect(pbkdf2Iterations((new Pbkdf2Hasher(1))->make('x')))->toBe(Pbkdf2Hasher::MIN_ITERATIONS);

    new Pbkdf2Hasher(Pbkdf2Hasher::MAX_ITERATIONS + 1);
})->throws(RuntimeException::class);

it('keeps the precomputed bcrypt hash in the user factory usable for login', function () {
    $user = User::factory()->create(['email' => 'factory@example.com']);

    expect($user->password)->toBe(LEGACY_FACTORY_BCRYPT);

    $this->post('/login', ['email' => 'factory@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user->fresh());
});

it('upgrades a bcrypt password hash to pbkdf2 on successful login without touching password age', function () {
    $changedAt = now()->subDays(10)->startOfSecond();
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => password_hash('Legacy!Pass1', PASSWORD_BCRYPT, ['cost' => 4]),
        'password_changed_at' => $changedAt,
    ]);

    $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'Legacy!Pass1']);

    $this->assertAuthenticatedAs($user->fresh());
    $user->refresh();

    expect($user->password)->toStartWith('$pbkdf2-sha256$')
        ->and(Hash::check('Legacy!Pass1', $user->password))->toBeTrue()
        ->and($user->password_changed_at->equalTo($changedAt))->toBeTrue();
});

it('upgrades a pbkdf2 hash with fewer iterations on login', function () {
    $user = User::factory()->create([
        'email' => 'weak@example.com',
        'password' => (new Pbkdf2Hasher(1000))->make('Corr3ct!Pass'),
    ]);
    config()->set('security.password.pbkdf2_iterations', 1500);
    Hash::forgetDrivers();

    $this->post('/login', ['email' => 'weak@example.com', 'password' => 'Corr3ct!Pass']);

    $this->assertAuthenticatedAs($user->fresh());
    expect(pbkdf2Iterations($user->fresh()->password))->toBe(1500);
});

it('does not rehash on a failed login', function () {
    $legacy = password_hash('Legacy!Pass1', PASSWORD_BCRYPT, ['cost' => 4]);
    $user = User::factory()->create(['email' => 'legacy@example.com', 'password' => $legacy]);

    $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'Wrong!Pass1']);

    $this->assertGuest();
    expect($user->fresh()->password)->toBe($legacy);
});

it('does not rewrite a current pbkdf2 hash on login', function () {
    $hash = Hash::make('Corr3ct!Pass');
    $user = User::factory()->create(['email' => 'current@example.com', 'password' => $hash]);

    $this->post('/login', ['email' => 'current@example.com', 'password' => 'Corr3ct!Pass']);

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->password)->toBe($hash);
});

it('upgrades the hash at the password step for users with two-factor authentication', function () {
    $user = User::factory()->create([
        'email' => 'twofactor@example.com',
        'password' => password_hash('Legacy!Pass1', PASSWORD_BCRYPT, ['cost' => 4]),
    ]);
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['email' => 'twofactor@example.com', 'password' => 'Legacy!Pass1'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect($user->fresh()->password)->toStartWith('$pbkdf2-sha256$');
});

it('respects hashing.rehash_on_login being disabled', function () {
    config()->set('hashing.rehash_on_login', false);
    $legacy = password_hash('Legacy!Pass1', PASSWORD_BCRYPT, ['cost' => 4]);
    $user = User::factory()->create(['email' => 'legacy@example.com', 'password' => $legacy]);

    $this->post('/login', ['email' => 'legacy@example.com', 'password' => 'Legacy!Pass1']);

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->password)->toBe($legacy);
});
