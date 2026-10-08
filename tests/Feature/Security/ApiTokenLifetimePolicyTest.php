<?php

use App\Livewire\Security\ApiTokens;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config()->set('security.api_tokens.max_lifetime_days', 90);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('the never option is gone and presets above the maximum are hidden', function () {
    Livewire::test(ApiTokens::class)
        ->assertSet('expirationOptions', fn (array $options) => array_keys($options) === [7, 30, 60, 90])
        ->assertSet('expiresInDays', 30)
        ->assertDontSee('1 year');
});

test('a maximum that is not a preset is offered as the longest option', function () {
    config()->set('security.api_tokens.max_lifetime_days', 45);

    expect(ApiTokens::allowedExpirationDays())->toBe([7, 30, 45]);

    config()->set('security.api_tokens.max_lifetime_days', 5);

    expect(ApiTokens::allowedExpirationDays())->toBe([5]);
    Livewire::test(ApiTokens::class)->assertSet('expiresInDays', 5);
});

test('tokens without expiry or above the maximum are rejected server side', function (mixed $days) {
    Livewire::test(ApiTokens::class)
        ->set('description', 'too-long')
        ->set('expiresInDays', $days)
        ->set('permissions', ['read'])
        ->call('addNewToken', 'password')
        ->assertDispatched('error');

    expect($this->user->tokens()->count())->toBe(0);
})->with([
    'never (null)' => [null],
    'never (empty)' => [''],
    'one year' => [365],
    'one day above the maximum' => [91],
]);

test('a token is created with an expiry within the maximum', function () {
    Livewire::test(ApiTokens::class)
        ->set('description', 'ci-token')
        ->set('expiresInDays', '90')
        ->set('permissions', ['read'])
        ->call('addNewToken', 'password')
        ->assertReturned(true)
        ->assertHasNoErrors();

    $token = $this->user->tokens()->sole();
    expect($token->expires_at)->not->toBeNull()
        ->and((int) round(now()->diffInDays($token->expires_at)))->toBe(90);
});

test('creating a token requires the current password', function () {
    Livewire::test(ApiTokens::class)
        ->set('description', 'ci-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['read'])
        ->call('addNewToken', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->assertHasErrors('password');

    expect($this->user->tokens()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'ui.api_token.created')->where('result', 'denied')->exists())->toBeTrue();

    Livewire::test(ApiTokens::class)
        ->set('description', 'ci-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['read'])
        ->call('addNewToken')
        ->assertReturned('The provided password is incorrect.');

    expect($this->user->tokens()->count())->toBe(0);
});

test('existing tokens that outlive the policy are flagged but kept', function () {
    $never = $this->user->createToken('legacy-never', ['read']);
    $tooLong = $this->user->createToken('legacy-year', ['read'], now()->addYear());
    $compliant = $this->user->createToken('compliant', ['read'], now()->addDays(30));

    $component = Livewire::test(ApiTokens::class)
        ->assertSee('Exceeds policy')
        ->assertSee('2 issued token(s) never expire or outlive the maximum token lifetime.', false);

    $instance = $component->instance();
    expect($instance->exceedsLifetimePolicy($never->accessToken->fresh()))->toBeTrue()
        ->and($instance->exceedsLifetimePolicy($tooLong->accessToken->fresh()))->toBeTrue()
        ->and($instance->exceedsLifetimePolicy($compliant->accessToken->fresh()))->toBeFalse()
        ->and($this->user->tokens()->count())->toBe(3);
});

test('no warning is shown when every token complies', function () {
    $this->user->createToken('compliant', ['read'], now()->addDays(30));

    Livewire::test(ApiTokens::class)->assertDontSee('Exceeds policy');
});
