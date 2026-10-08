<?php

use App\Http\Middleware\EnforceSessionTimeout;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\ShowHardcoded;
use App\Livewire\SharedVariables\Server\Show as ServerSharedVariables;
use App\Livewire\SharedVariables\Team\Index as TeamSharedVariables;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use App\Support\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    $this->withoutDefer();

    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'git_repository' => null,
    ]);

    EnvironmentVariable::create([
        'key' => 'API_SECRET',
        'value' => 'top-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);
    EnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'database-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function bulkRevealAuditEvents(string $event = 'secret.environment_variable.bulk_revealed'): Collection
{
    return AuditEvent::query()->where('event', $event)->orderBy('id')->get();
}

function bulkEnvironmentVariablesComponent(): mixed
{
    return Livewire::test(All::class, ['resource' => test()->application])
        ->call('loadEnvironmentVariables');
}

// --- Environment variable developer view ---

test('the developer view hides values until the user re-authenticates', function () {
    $component = bulkEnvironmentVariablesComponent()->call('switch');

    expect($component->get('variables'))
        ->toContain('API_SECRET='.All::HIDDEN_SECRET_PLACEHOLDER)
        ->toContain('DB_PASSWORD='.All::HIDDEN_SECRET_PLACEHOLDER)
        ->and($component->get('sensitiveConfirmationRequired'))->toBeTrue()
        ->and($component->get('devValuesRevealed'))->toBeFalse()
        ->and(json_encode($component->snapshot).$component->html())
        ->not->toContain('top-secret-value')
        ->not->toContain('database-secret-value');

    $component->assertSee('Values are hidden until you confirm your identity.')
        ->assertSeeHtml('data-sensitive-confirmation');

    expect(bulkRevealAuditEvents())->toBeEmpty();
});

test('confirming with the password reveals the developer view values and audits the count', function () {
    $component = bulkEnvironmentVariablesComponent()->call('switch');

    $component->call('confirmSensitiveAccess', 'wrong-password')
        ->assertReturned('The provided password is incorrect.');

    expect($component->get('variables'))->not->toContain('top-secret-value')
        ->and(bulkRevealAuditEvents()->sole()->result)->toBe('denied');

    $component->call('confirmSensitiveAccess', 'password')->assertReturned(true);

    expect($component->get('variables'))
        ->toContain('API_SECRET=top-secret-value')
        ->toContain('DB_PASSWORD=database-secret-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeFalse()
        ->and($component->get('devValuesRevealed'))->toBeTrue();

    $event = bulkRevealAuditEvents()->last();
    expect($event->result)->toBe('success')
        ->and(data_get($event->metadata, 'variable_count'))->toBe($this->application->environment_variables()->count())
        ->and(data_get($event->metadata, 'resource_type'))->toBe('Application')
        ->and(data_get($event->metadata, 'resource_uuid'))->toBe($this->application->uuid)
        ->and(json_encode($event->metadata))
        ->not->toContain('top-secret-value')
        ->not->toContain('API_SECRET');

    // Refreshing the already revealed view does not record another reveal.
    $component->call('refreshEnvs');
    expect(bulkRevealAuditEvents()->where('result', 'success'))->toHaveCount(1);
});

test('a recent confirmation reveals the developer view immediately', function () {
    session([Reauthentication::CONFIRMED_AT_KEY => now()->getTimestamp()]);

    $component = bulkEnvironmentVariablesComponent()->call('switch');

    expect($component->get('variables'))->toContain('API_SECRET=top-secret-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeFalse()
        ->and(bulkRevealAuditEvents()->where('result', 'success'))->toHaveCount(1);
});

test('the developer view hides values again once the confirmation expires', function () {
    session([Reauthentication::CONFIRMED_AT_KEY => now()->getTimestamp()]);
    $component = bulkEnvironmentVariablesComponent()->call('switch');
    expect($component->get('variables'))->toContain('top-secret-value');

    $this->travel(Reauthentication::windowMinutes() + 1)->minutes();
    $component->call('refreshEnvs');

    expect($component->get('variables'))->not->toContain('top-secret-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeTrue();
});

test('sso users who signed in recently see developer view values without a prompt', function () {
    OauthIdentity::create([
        'user_id' => $this->user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-'.$this->user->id,
        'email' => $this->user->email,
    ]);
    session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinute()->getTimestamp()]);

    $component = bulkEnvironmentVariablesComponent()->call('switch');

    expect($component->get('variables'))->toContain('API_SECRET=top-secret-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeFalse();
});

test('saving the developer view without revealed values keeps the stored values', function () {
    bulkEnvironmentVariablesComponent()
        ->call('switch')
        ->set('variables', implode("\n", [
            'API_SECRET='.All::HIDDEN_SECRET_PLACEHOLDER,
            'DB_PASSWORD=new-database-value',
            'NEW_FROM_PLACEHOLDER='.All::HIDDEN_SECRET_PLACEHOLDER,
        ]))
        ->call('submit')
        ->assertHasNoErrors();

    $values = $this->application->environment_variables()->get()->pluck('value', 'key')->all();

    expect($values)->toMatchArray([
        'API_SECRET' => 'top-secret-value',
        'DB_PASSWORD' => 'new-database-value',
    ])->and($values)->not->toHaveKey('NEW_FROM_PLACEHOLDER');
});

test('closing the developer view drops the decrypted values from the component state', function () {
    session([Reauthentication::CONFIRMED_AT_KEY => now()->getTimestamp()]);

    $component = bulkEnvironmentVariablesComponent()->call('switch')->call('switch');

    expect($component->get('view'))->toBe('normal')
        ->and($component->get('variables'))->toBeNull()
        ->and($component->get('devValuesRevealed'))->toBeFalse();
});

test('members keep seeing hidden values without a confirmation prompt', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user->fresh());
    session([Reauthentication::CONFIRMED_AT_KEY => now()->getTimestamp()]);

    $component = bulkEnvironmentVariablesComponent()->call('switch');

    expect($component->get('variables'))
        ->toContain('API_SECRET=(Hidden, only admins can view)')
        ->not->toContain('top-secret-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeFalse();
});

test('the developer view gate cannot be changed by the client', function (string $property, bool $value) {
    bulkEnvironmentVariablesComponent()->set($property, $value);
})->with([
    'confirmation required' => ['sensitiveConfirmationRequired', false],
    'values revealed' => ['devValuesRevealed', true],
])->throws(CannotUpdateLockedPropertyException::class);

// --- Compose-managed (hard-coded) rows ---

function hardcodedRowComponent(): mixed
{
    return Livewire::test(ShowHardcoded::class, [
        'env' => ['key' => 'DB_PASSWORD', 'value' => '$API_SECRET'],
        'resourceableType' => Application::class,
        'resourceableId' => test()->application->id,
    ]);
}

test('copying a compose-managed value needs a recent confirmation and is audited', function () {
    $component = hardcodedRowComponent()
        ->call('copyValue')
        ->assertReturned(null)
        ->assertSet('sensitiveConfirmationRequired', true);

    $denied = bulkRevealAuditEvents('secret.environment_variable.copied')->sole();
    expect($denied->result)->toBe('denied')
        ->and(data_get($denied->metadata, 'hardcoded'))->toBeTrue();

    $component->call('confirmSensitiveAccess', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->call('confirmSensitiveAccess', 'password')
        ->assertReturned(true)
        ->assertSet('sensitiveConfirmationRequired', false)
        ->call('copyValue')
        ->assertReturned('top-secret-value');

    $success = bulkRevealAuditEvents('secret.environment_variable.copied')->last();
    expect($success->result)->toBe('success')
        ->and(data_get($success->metadata, 'variable_key'))->toBe('DB_PASSWORD')
        ->and(data_get($success->metadata, 'resource_uuid'))->toBe($this->application->uuid)
        ->and(json_encode($success->metadata))->not->toContain('top-secret-value');
});

test('the compose-managed confirmation state cannot be cleared by the client', function () {
    hardcodedRowComponent()->set('sensitiveConfirmationRequired', false);
})->throws(CannotUpdateLockedPropertyException::class);

// --- Shared variable developer views ---

test('shared variable developer view hides values until the user re-authenticates', function () {
    SharedEnvironmentVariable::create([
        'key' => 'TEAM_TOKEN',
        'value' => 'team-token-value',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(TeamSharedVariables::class);

    // The bulk text is not built while the normal view is shown.
    expect($component->get('variables'))->toBeNull();

    $component->call('switch')
        ->assertSee('Values are hidden until you confirm your identity.');

    expect($component->get('variables'))->toBe('TEAM_TOKEN='.TeamSharedVariables::HIDDEN_SECRET_PLACEHOLDER)
        ->and($component->get('sensitiveConfirmationRequired'))->toBeTrue()
        ->and(json_encode($component->snapshot).$component->html())->not->toContain('team-token-value');

    $component->call('confirmSensitiveAccess', 'password')->assertReturned(true);

    expect($component->get('variables'))->toBe('TEAM_TOKEN=team-token-value')
        ->and($component->get('sensitiveConfirmationRequired'))->toBeFalse();

    $event = bulkRevealAuditEvents()->sole();
    expect($event->result)->toBe('success')
        ->and(data_get($event->metadata, 'resource_type'))->toBe('shared_team')
        ->and(data_get($event->metadata, 'variable_count'))->toBe(1)
        ->and(json_encode($event->metadata))->not->toContain('team-token-value');
});

test('shared variable developer view state cannot be changed by the client', function () {
    Livewire::test(TeamSharedVariables::class)->set('devValuesRevealed', true);
})->throws(CannotUpdateLockedPropertyException::class);

test('saving a hidden shared variable developer view keeps stored values', function () {
    makePlatformAdmin($this->user);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    SharedEnvironmentVariable::create([
        'key' => 'SERVER_TOKEN',
        'value' => 'server-token-value',
        'comment' => 'old comment',
        'type' => 'server',
        'server_id' => $server->id,
        'team_id' => $this->team->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'SERVER_OTHER',
        'value' => 'other-value',
        'type' => 'server',
        'server_id' => $server->id,
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(ServerSharedVariables::class, ['server_uuid' => $server->uuid])->call('switch');

    expect($component->get('variables'))->not->toContain('server-token-value');

    $component->set('variables', $component->get('variables')."\nSERVER_NEW=new-value")
        ->call('submit')
        ->assertHasNoErrors();

    $values = $server->environment_variables()->get()->pluck('value', 'key')->all();

    expect($values)->toMatchArray([
        'SERVER_TOKEN' => 'server-token-value',
        'SERVER_OTHER' => 'other-value',
        'SERVER_NEW' => 'new-value',
    ]);
});

test('submitting shared variables without developer view text deletes nothing', function () {
    makePlatformAdmin($this->user);
    SharedEnvironmentVariable::create([
        'key' => 'TEAM_TOKEN',
        'value' => 'team-token-value',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    Livewire::test(TeamSharedVariables::class)->call('submit');

    expect($this->team->environment_variables()->where('key', 'TEAM_TOKEN')->exists())->toBeTrue();
});
