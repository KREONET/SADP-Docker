<?php

use App\Livewire\ForcePasswordReset;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Once;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => true]);
    Once::flush();
});

function passwordPolicyPasses(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();
}

it('requires the configured length with mixed case, numbers and symbols in every environment', function (string $password, bool $expected) {
    expect(app()->environment())->not->toBe('production')
        ->and(passwordPolicyPasses($password))->toBe($expected);
})->with([
    'strong' => ['Str0ng!Pass', true],
    'exactly nine characters' => ['Abcdef1!x', true],
    'eight characters' => ['Abcde1!x', false],
    'letters only' => ['passwordpassword', false],
    'no symbol' => ['Password1234', false],
    'no number' => ['Password!!!!', false],
    'no uppercase' => ['password1234!', false],
    'no lowercase' => ['PASSWORD1234!', false],
]);

it('reads the minimum length from the security config', function () {
    config()->set('security.password.min_length', 14);

    expect(passwordPolicyPasses('Str0ng!Pass'))->toBeFalse()
        ->and(passwordPolicyPasses('Str0ng!Password1'))->toBeTrue();
});

it('checks the breach corpus only when enabled', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    passwordPolicyPasses('Str0ng!Pass');
    Http::assertNothingSent();

    config()->set('security.password.check_breached', true);
    passwordPolicyPasses('Str0ng!Pass');
    Http::assertSentCount(1);
});

it('rejects weak passwords on registration', function () {
    $this->post('/register', [
        'name' => 'Weak',
        'email' => 'weak@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('stamps password_changed_at when a user registers', function () {
    $this->post('/register', [
        'name' => 'Strong',
        'email' => 'strong@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ])->assertSessionHasNoErrors();

    expect(User::query()->sole()->password_changed_at)->not->toBeNull();
});

it('refuses a weak password in root:reset-password', function () {
    $root = User::factory()->create(['id' => 0, 'password' => Hash::make('Old!Passw0rd')]);

    $this->artisan('root:reset-password')
        ->expectsQuestion('Give me a new password for root user: ', 'password')
        ->expectsQuestion('Again', 'password')
        ->expectsOutputToContain('does not meet the password policy')
        ->assertFailed();

    expect(Hash::check('Old!Passw0rd', $root->fresh()->password))->toBeTrue();
});

it('refuses mismatched passwords in root:reset-password', function () {
    User::factory()->create(['id' => 0]);

    $this->artisan('root:reset-password')
        ->expectsQuestion('Give me a new password for root user: ', 'Str0ng!Pass')
        ->expectsQuestion('Again', 'Str0ng!Pazz')
        ->expectsOutput('Passwords do not match.')
        ->assertFailed();
});

it('refuses to reuse the current root password in root:reset-password', function () {
    User::factory()->create(['id' => 0, 'password' => Hash::make('Old!Passw0rd')]);

    $this->artisan('root:reset-password')
        ->expectsQuestion('Give me a new password for root user: ', 'Old!Passw0rd')
        ->expectsQuestion('Again', 'Old!Passw0rd')
        ->assertFailed();
});

it('sets a strong root password, stamps the change and lifts a lockout', function () {
    $root = User::factory()->create(['id' => 0, 'password' => Hash::make('Old!Passw0rd')]);
    $root->forceFill([
        'password_changed_at' => now()->subYear(),
        'failed_login_attempts' => 5,
        'locked_until' => now()->addMinutes(30),
    ])->saveQuietly();

    $this->artisan('root:reset-password')
        ->expectsQuestion('Give me a new password for root user: ', 'New!Passw0rd')
        ->expectsQuestion('Again', 'New!Passw0rd')
        ->expectsOutput('Root password updated successfully.')
        ->assertSuccessful();

    $root->refresh();
    expect(Hash::check('New!Passw0rd', $root->password))->toBeTrue()
        ->and($root->password_changed_at->isToday())->toBeTrue()
        ->and($root->isLockedOut())->toBeFalse()
        ->and($root->failed_login_attempts)->toBe(0);
});

it('applies the password policy on the forced password change screen', function () {
    $user = User::factory()->create(['force_password_reset' => true]);
    $this->actingAs($user);

    Livewire::test(ForcePasswordReset::class)
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('submit')
        ->assertDispatched('error');

    expect($user->fresh()->force_password_reset)->toBeTrue()
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});
