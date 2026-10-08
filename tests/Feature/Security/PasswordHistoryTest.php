<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Livewire\ForcePasswordReset;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\InstanceSettings;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Rules\NotRecentlyUsedPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Once;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('security.password.history', 3);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

function changePasswordTo(User $user, string $password): void
{
    $user->update(['password' => Hash::make($password)]);
}

function passesHistoryRule(User $user, string $password): bool
{
    return Validator::make(['password' => $password], ['password' => [new NotRecentlyUsedPassword($user->fresh())]])->passes();
}

it('stores the previous hash when the password changes', function () {
    $user = User::factory()->create(['password' => Hash::make('First!Pass1')]);
    $firstHash = $user->password;

    changePasswordTo($user, 'Second!Pass2');

    expect(PasswordHistory::query()->where('user_id', $user->id)->pluck('password')->all())->toBe([$firstHash]);
});

it('does not store history when other attributes change', function () {
    $user = User::factory()->create();

    $user->update(['name' => 'Renamed']);

    expect(PasswordHistory::query()->count())->toBe(0);
});

it('keeps only the configured number of previous passwords', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);

    foreach (range(1, 5) as $i) {
        changePasswordTo($user, "Pass!word{$i}");
    }

    expect(PasswordHistory::query()->where('user_id', $user->id)->count())->toBe(3)
        ->and(passesHistoryRule($user, 'Pass!word5'))->toBeFalse()
        ->and(passesHistoryRule($user, 'Pass!word4'))->toBeFalse()
        ->and(passesHistoryRule($user, 'Pass!word2'))->toBeFalse()
        ->and(passesHistoryRule($user, 'Pass!word1'))->toBeTrue()
        ->and(passesHistoryRule($user, 'Pass!word0'))->toBeTrue();
});

it('only rejects the current password when history is disabled', function () {
    config()->set('security.password.history', 0);
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    changePasswordTo($user, 'Pass!word1');

    expect(PasswordHistory::query()->count())->toBe(0)
        ->and(passesHistoryRule($user, 'Pass!word1'))->toBeFalse()
        ->and(passesHistoryRule($user, 'Pass!word0'))->toBeTrue();
});

it('rejects a recent password on the fortify password reset', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    changePasswordTo($user, 'Pass!word1');

    expect(fn () => (new ResetUserPassword)->reset($user->fresh(), [
        'password' => 'Pass!word0',
        'password_confirmation' => 'Pass!word0',
    ]))->toThrow(ValidationException::class);

    (new ResetUserPassword)->reset($user->fresh(), [
        'password' => 'Pass!word9',
        'password_confirmation' => 'Pass!word9',
    ]);
    expect(Hash::check('Pass!word9', $user->fresh()->password))->toBeTrue();
});

it('rejects a recent password on the fortify password update', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    $this->actingAs($user);

    expect(fn () => (new UpdateUserPassword)->update($user->fresh(), [
        'current_password' => 'Pass!word0',
        'password' => 'Pass!word0',
        'password_confirmation' => 'Pass!word0',
    ]))->toThrow(ValidationException::class);
});

it('does not reveal the reuse check before the current password is verified', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    $this->actingAs($user);

    try {
        (new UpdateUserPassword)->update($user->fresh(), [
            'current_password' => 'Wrong!Guess1',
            'password' => 'Pass!word0',
            'password_confirmation' => 'Pass!word0',
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('current_password')
            ->not->toHaveKey('password');
    }
});

it('rejects a recent password on the profile page', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    changePasswordTo($user, 'Pass!word1');
    $this->actingAs($user->fresh());

    Livewire::test(ProfileIndex::class)
        ->set('current_password', 'Pass!word1')
        ->set('new_password', 'Pass!word0')
        ->set('new_password_confirmation', 'Pass!word0')
        ->call('resetPassword')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect(Hash::check('Pass!word1', $user->fresh()->password))->toBeTrue();
});

it('rejects the current password on the forced change screen', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0'), 'force_password_reset' => true]);
    $this->actingAs($user);

    Livewire::test(ForcePasswordReset::class)
        ->set('password', 'Pass!word0')
        ->set('password_confirmation', 'Pass!word0')
        ->call('submit')
        ->assertDispatched('error');

    expect($user->fresh()->force_password_reset)->toBeTrue();
});

it('deletes the history together with the user', function () {
    $user = User::factory()->create(['password' => Hash::make('Pass!word0')]);
    changePasswordTo($user, 'Pass!word1');

    $user->delete();

    expect(PasswordHistory::query()->count())->toBe(0);
});
