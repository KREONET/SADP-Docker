<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Notifications\TransactionalEmails\EmailAddressChanged;
use App\Notifications\TransactionalEmails\EmailChangeVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'smtp_enabled' => true]);
    Once::flush();
    Notification::fake();

    $this->user = User::factory()->create([
        'name' => 'Profile User',
        'email' => 'old@example.com',
        'password' => Hash::make('Corr3ct!Pass'),
    ]);
    $this->actingAs($this->user);
});

it('requires the current password before sending a change code', function () {
    Livewire::test(ProfileIndex::class)
        ->set('new_email', 'new@example.com')
        ->call('requestEmailChange')
        ->assertDispatched('error')
        ->assertSet('show_verification', false);

    expect($this->user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses a wrong current password', function () {
    Livewire::test(ProfileIndex::class)
        ->set('new_email', 'new@example.com')
        ->set('email_change_password', 'Wrong!Pass1')
        ->call('requestEmailChange')
        ->assertHasErrors('email_change_password')
        ->assertSet('email_change_password', '')
        ->assertSet('show_verification', false);

    expect($this->user->fresh()->pending_email)->toBeNull();
    Notification::assertNothingSent();
});

it('limits wrong current password guesses', function () {
    $key = 'email-change:password:'.$this->user->id;
    RateLimiter::clear($key);
    foreach (range(1, 5) as $attempt) {
        RateLimiter::hit($key, 600);
    }

    Livewire::test(ProfileIndex::class)
        ->set('new_email', 'new@example.com')
        ->set('email_change_password', 'Corr3ct!Pass')
        ->call('requestEmailChange')
        ->assertDispatched('error');

    expect($this->user->fresh()->pending_email)->toBeNull();
    RateLimiter::clear($key);
});

it('sends the change code after the correct password', function () {
    Livewire::test(ProfileIndex::class)
        ->set('new_email', 'new@example.com')
        ->set('email_change_password', 'Corr3ct!Pass')
        ->call('requestEmailChange')
        ->assertHasNoErrors()
        ->assertSet('show_verification', true)
        ->assertSet('email_change_password', '');

    expect($this->user->fresh()->pending_email)->toBe('new@example.com');
    Notification::assertSentTo($this->user, EmailChangeVerification::class);
});

it('notifies the previous address after the change is confirmed', function () {
    $this->user->requestEmailChange('new@example.com');
    $code = $this->user->fresh()->email_change_code;

    Livewire::test(ProfileIndex::class)
        ->set('email_verification_code', $code)
        ->call('verifyEmailChange')
        ->assertDispatched('success');

    expect($this->user->fresh()->email)->toBe('new@example.com');
    Notification::assertSentTo(
        $this->user,
        EmailAddressChanged::class,
        fn (EmailAddressChanged $notification): bool => $notification->newEmail === 'old@example.com'
            && $notification->previousEmail === 'old@example.com'
    );
});

it('renders the old-address notice', function () {
    $mail = (new EmailAddressChanged('old@example.com', now()))->toMail();

    expect((string) $mail->render())->toContain('old@example.com')
        ->toContain(__('If you did not make this change, contact your administrator immediately.'));
});
