<?php

use App\Actions\Stripe\RefundSubscription;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Livewire\Subscription\Actions;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Support\Reauthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_test_123',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('cancelImmediately with refund option', function () {
    test('refunds and cancels via RefundSubscription when refund checkbox is selected', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));
    });

    test('dispatches error when refund fails', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => false, 'error' => 'No paid invoice found to refund.']);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('error');
    });

    test('rejects invalid password before refunding', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'wrong-password', ['refundLatestPayment'])
            ->assertReturned('The provided password is incorrect.');
    });
});

describe('re-authentication before billing changes', function () {
    beforeEach(function () {
        OauthIdentity::create([
            'user_id' => $this->user->id,
            'provider' => 'oidc',
            'issuer' => 'https://idp.example.com',
            'provider_user_id' => 'idp-'.$this->user->id,
            'email' => $this->user->email,
        ]);
    });

    test('sso users with two-factor authentication must enter a current code', function () {
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        // A recent sign-in alone is not enough when a second factor is available.
        session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->getTimestamp()]);

        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('refundSubscription', '')
            ->assertReturned('The provided two-factor code is invalid.')
            ->call('refundSubscription', '000000')
            ->assertReturned('The provided two-factor code is invalid.')
            ->call('refundSubscription', app(Google2FA::class)->getCurrentOtp($secret))
            ->assertReturned(true);
    });

    test('sso users without two-factor authentication must have signed in recently', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinutes(Reauthentication::windowMinutes() + 1)->getTimestamp()]);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', '', ['refundLatestPayment'])
            ->assertReturned('For security, please sign in again to confirm this action.');

        session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->getTimestamp()]);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', '', ['refundLatestPayment'])
            ->assertRedirect(route('subscription.index'));
    });

    test('cancelling at period end rejects a stale sso sign-in', function () {
        session([EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinutes(Reauthentication::windowMinutes() + 1)->getTimestamp()]);

        Livewire::test(Actions::class)
            ->call('cancelAtPeriodEnd', '')
            ->assertReturned('For security, please sign in again to confirm this action.');

        expect(Subscription::query()->where('team_id', $this->team->id)->value('stripe_cancel_at_period_end'))->toBeFalsy();
    });
});

test('password users still confirm billing changes with their password', function () {
    $mock = Mockery::mock(RefundSubscription::class);
    $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
    $this->instance(RefundSubscription::class, $mock);

    Livewire::test(Actions::class)
        ->call('refundSubscription', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->call('refundSubscription', 'password')
        ->assertReturned(true);
});
