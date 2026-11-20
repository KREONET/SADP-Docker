<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('builds the ssh heredoc terminator without hashing the command', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);

    // Password hashing (PBKDF2, 600000 iterations) must never run per SSH command.
    Hash::shouldReceive('make')->never();

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo hello', disableMultiplexing: true);

    expect($command)->toMatch('/<< \\\\(EOF_[A-Za-z0-9]{32})\n.*echo hello\n\1$/s');
});

it('strips a terminator that appears inside the command', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $command = SshMultiplexingHelper::generateSshCommand($server, 'echo hello', disableMultiplexing: true);
    preg_match('/<< \\\\(EOF_[A-Za-z0-9]{32})/', $command, $matches);

    expect(substr_count($command, $matches[1]))->toBe(2);
});

it('generates the requested key type and defaults to ECDSA', function (?string $type, string $expectedPrefix) {
    $keyPair = $type === null ? PrivateKey::generateNewKeyPair() : PrivateKey::generateNewKeyPair($type);

    expect($keyPair['public_key'] ?? PrivateKey::extractPublicKeyFromPrivate($keyPair['private_key']))
        ->toStartWith($expectedPrefix);
})->with([
    'default' => [null, 'ecdsa-sha2-nistp256'],
    'ecdsa' => ['ecdsa', 'ecdsa-sha2-nistp256'],
    'rsa' => ['rsa', 'ssh-rsa'],
    'ed25519' => ['ed25519', 'ssh-ed25519'],
]);
