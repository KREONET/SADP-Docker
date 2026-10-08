<?php

use App\Http\Middleware\EnforceSessionTimeout;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Livewire\Security\PrivateKey\Show as PrivateKeyShow;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    $this->withoutDefer();
    Storage::fake('ssh-keys');

    $this->settings = InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function reauthAuditEvents(string $event): Collection
{
    return AuditEvent::query()->where('event', $event)->orderBy('id')->get();
}

function makeSsoUser(User $user): void
{
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-'.$user->id,
        'email' => $user->email,
    ]);
}

function enableTwoFactor(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

function secretEnvironmentVariable(): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => 'API_SECRET',
        'value' => 'top-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ]);
}

// --- verifyPasswordConfirmation() ---

test('the legacy two-step setting no longer bypasses password confirmation', function () {
    $this->settings->forceFill(['disable_two_step_confirmation' => true])->save();

    expect(shouldSkipPasswordConfirmation())->toBeFalse()
        ->and(verifyPasswordConfirmation(''))->toBeFalse()
        ->and(verifyPasswordConfirmation('wrong-password'))->toBeFalse()
        ->and(verifyPasswordConfirmation('password'))->toBeTrue();
});

test('a successful confirmation is remembered for the re-authentication window', function () {
    expect(hasRecentSensitiveConfirmation())->toBeFalse();

    verifyPasswordConfirmation('password');

    expect(hasRecentSensitiveConfirmation())->toBeTrue();

    $this->travel(Reauthentication::windowMinutes() + 1)->minutes();

    expect(hasRecentSensitiveConfirmation())->toBeFalse();
});

test('failed confirmations are audited without the credential', function () {
    verifyPasswordConfirmation('wrong-password');

    $event = reauthAuditEvents('auth.reauthentication.failed')->sole();

    expect($event->result)->toBe('failure')
        ->and(data_get($event->metadata, 'reauthentication_method'))->toBe('password')
        ->and(json_encode($event->metadata))->not->toContain('wrong-password');
});

test('repeated wrong passwords are throttled', function () {
    foreach (range(1, 5) as $attempt) {
        verifyPasswordConfirmation('wrong-password');
    }

    expect(verifyPasswordConfirmation('password'))->toBeFalse();
});

test('sso users without two-factor authentication must have signed in recently', function () {
    makeSsoUser($this->user);

    expect(reauthenticationMethod())->toBe('recent_login')
        ->and(shouldSkipPasswordConfirmation())->toBeTrue()
        ->and(verifyPasswordConfirmation(''))->toBeFalse();

    session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinutes(Reauthentication::windowMinutes() + 1)->getTimestamp()]);
    expect(verifyPasswordConfirmation(''))->toBeFalse();

    session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinutes(2)->getTimestamp()]);
    expect(verifyPasswordConfirmation(''))->toBeTrue();
});

test('sso users with two-factor authentication confirm with a current totp code', function () {
    makeSsoUser($this->user);
    $secret = enableTwoFactor($this->user);
    // Signing in recently is not enough when a code can be asked for.
    session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->getTimestamp()]);

    expect(reauthenticationMethod())->toBe('totp')
        ->and(shouldSkipPasswordConfirmation())->toBeFalse()
        ->and(verifyPasswordConfirmation(''))->toBeFalse()
        ->and(verifyPasswordConfirmation('password'))->toBeFalse()
        ->and(verifyPasswordConfirmation('000000'))->toBeFalse()
        ->and(verifyPasswordConfirmation(app(Google2FA::class)->getCurrentOtp($secret)))->toBeTrue();
});

test('the confirmation modal asks sso users with two-factor authentication for a code', function () {
    makeSsoUser($this->user);
    enableTwoFactor($this->user);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSeeHtml('autocomplete="one-time-code"')
        ->assertDontSeeHtml('autocomplete="current-password"');
});

// --- Environment variable values ---

test('revealing an environment variable value requires re-authentication', function () {
    $component = Livewire::test(EnvironmentVariableShow::class, ['env' => secretEnvironmentVariable(), 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('valuesLoaded', false)
        ->assertSet('value', null)
        ->assertSet('sensitiveConfirmationRequired', true)
        ->assertSeeHtml('data-sensitive-confirmation')
        ->assertDontSee('top-secret-value');

    $component->call('confirmSensitiveAccess', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->assertSet('value', null);

    $component->call('confirmSensitiveAccess', 'password')
        ->assertReturned(true)
        ->assertSet('valuesLoaded', true)
        ->assertSet('value', 'top-secret-value')
        ->assertSet('sensitiveConfirmationRequired', false);

    $events = reauthAuditEvents('secret.environment_variable.revealed');
    expect($events->pluck('result')->all())->toBe(['denied', 'denied', 'success'])
        ->and(data_get($events->last()->metadata, 'variable_key'))->toBe('API_SECRET')
        ->and(data_get($events->last()->metadata, 'resource_type'))->toBe('Application')
        ->and(data_get($events->last()->metadata, 'resource_uuid'))->toBe($this->application->uuid)
        ->and($events->map(fn (AuditEvent $event) => json_encode($event->metadata))->implode(''))->not->toContain('top-secret-value');
});

test('a recent confirmation reveals other values without asking again', function () {
    verifyPasswordConfirmation('password');

    Livewire::test(EnvironmentVariableShow::class, ['env' => secretEnvironmentVariable(), 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('value', 'top-secret-value')
        ->assertSet('sensitiveConfirmationRequired', false);

    $this->travel(Reauthentication::windowMinutes() + 1)->minutes();

    Livewire::test(EnvironmentVariableShow::class, ['env' => secretEnvironmentVariable(), 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('value', null)
        ->assertSet('sensitiveConfirmationRequired', true);
});

test('copying an environment variable value requires re-authentication and is audited', function () {
    $env = secretEnvironmentVariable();

    Livewire::test(EnvironmentVariableShow::class, ['env' => $env, 'type' => 'application'])
        ->call('copyValue')
        ->assertReturned(null)
        ->assertSet('sensitiveConfirmationRequired', true);

    verifyPasswordConfirmation('password');

    Livewire::test(EnvironmentVariableShow::class, ['env' => $env, 'type' => 'application'])
        ->call('copyValue')
        ->assertReturned('top-secret-value');

    $events = reauthAuditEvents('secret.environment_variable.copied');
    expect($events->pluck('result')->all())->toBe(['denied', 'success'])
        ->and(data_get($events->last()->metadata, 'variable_key'))->toBe('API_SECRET')
        ->and($events->map(fn (AuditEvent $event) => json_encode($event->metadata))->implode(''))->not->toContain('top-secret-value');
});

test('recently signed-in sso users reveal values without typing anything', function () {
    makeSsoUser($this->user);
    session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->getTimestamp()]);

    Livewire::test(EnvironmentVariableShow::class, ['env' => secretEnvironmentVariable(), 'type' => 'application'])
        ->call('loadValues')
        ->assertSet('value', 'top-secret-value');
});

test('updating metadata without revealing the value keeps the stored value', function () {
    $env = secretEnvironmentVariable();

    Livewire::test(EnvironmentVariableShow::class, ['env' => $env, 'type' => 'application'])
        ->set('comment', 'Rotated quarterly')
        ->call('submit')
        ->assertDispatched('success');

    $env->refresh();
    expect($env->comment)->toBe('Rotated quarterly')
        ->and($env->value)->toBe('top-secret-value');
});

test('the client cannot mark values as loaded or confirmed', function (string $property) {
    Livewire::test(EnvironmentVariableShow::class, ['env' => secretEnvironmentVariable(), 'type' => 'application'])
        ->set($property, true);
})->with(['valuesLoaded', 'sensitiveConfirmationRequired'])->throws(CannotUpdateLockedPropertyException::class);

// --- Private keys ---

test('private key material is only sent after re-authentication', function () {
    $keyMaterial = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'private_key' => $keyMaterial]);

    $component = Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->assertSet('privateKeyValue', '')
        ->assertDontSee($keyMaterial);

    $component->call('showPrivateKey')
        ->assertReturned('Confirm your identity to view this private key.')
        ->assertSet('sensitiveConfirmationRequired', true)
        ->assertSet('privateKeyValue', '')
        ->assertSeeHtml('data-sensitive-confirmation');

    $component->call('showPrivateKey', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->assertSet('privateKeyValue', '');

    $component->call('showPrivateKey', 'password')
        ->assertReturned(true)
        ->assertSet('privateKeyRevealed', true)
        ->assertSet('privateKeyValue', $keyMaterial);

    $events = reauthAuditEvents('secret.private_key.revealed');
    expect($events->pluck('result')->all())->toBe(['denied', 'success'])
        ->and(data_get($events->last()->metadata, 'private_key_uuid'))->toBe($privateKey->uuid)
        ->and($events->map(fn (AuditEvent $event) => json_encode($event->metadata))->implode(''))->not->toContain('PRIVATE KEY');
});

test('members cannot reveal a private key even after re-authentication', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->call('showPrivateKey', 'password')
        ->assertReturned('You do not have permission to view this private key.')
        ->assertSet('privateKeyValue', '');

    expect(reauthAuditEvents('secret.private_key.revealed')->sole()->result)->toBe('denied');
});

test('saving a private key without revealing it keeps the stored key', function () {
    $keyMaterial = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'private_key' => $keyMaterial]);

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->set('name', 'Renamed key')
        ->call('changePrivateKey')
        ->assertHasNoErrors();

    $privateKey->refresh();
    expect($privateKey->name)->toBe('Renamed key')
        ->and($privateKey->private_key)->toBe($keyMaterial);
});

test('a new private key can replace the stored one without revealing it', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $replacement = PrivateKey::generateNewKeyPair('ed25519')['private_key'];

    Livewire::test(PrivateKeyShow::class, ['private_key_uuid' => $privateKey->uuid])
        ->set('privateKeyValue', $replacement)
        ->call('changePrivateKey')
        ->assertHasNoErrors();

    expect(trim($privateKey->fresh()->private_key))->toBe(trim($replacement));
});

// --- API sensitive reads ---

function sensitiveReadApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'sensitive-read-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    // The API must authenticate with the bearer token, not the user signed in by beforeEach().
    auth()->forgetGuards();

    return $token->getKey().'|'.$plainTextToken;
}

test('api reads served with sensitive data are audited once per request', function () {
    Project::factory()->count(3)->create(['team_id' => $this->team->id]);
    $token = sensitiveReadApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/projects')
        ->assertOk();

    $event = reauthAuditEvents('api.sensitive_data.read')->sole();
    expect(data_get($event->metadata, 'path'))->toBe('/api/v1/projects')
        ->and(data_get($event->metadata, 'token_name'))->toBe('sensitive-read-token')
        ->and($event->result)->toBe('success');
});

test('api reads without sensitive access are not audited as sensitive reads', function () {
    $token = sensitiveReadApiToken($this->user, $this->team, ['read']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/projects')
        ->assertOk();

    expect(reauthAuditEvents('api.sensitive_data.read'))->toBeEmpty();
});

test('members holding a read:sensitive token are not audited as sensitive reads', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $token = sensitiveReadApiToken($member, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v1/projects');

    expect(reauthAuditEvents('api.sensitive_data.read'))->toBeEmpty();
});
