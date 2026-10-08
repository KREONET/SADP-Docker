<?php

use App\Jobs\SendMessageToTelegramJob;
use App\Livewire\Notifications\Discord as DiscordNotification;
use App\Livewire\Notifications\Email as EmailNotification;
use App\Livewire\Notifications\Pushover as PushoverNotification;
use App\Livewire\Notifications\Slack as SlackNotification;
use App\Livewire\Notifications\Telegram as TelegramNotification;
use App\Livewire\Notifications\Webhook as WebhookNotification;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\SecurityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));
    Cache::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->rootTeam, ['role' => 'admin']);
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->rootTeam, ['role' => 'member']);
});

dataset('security alert channels', [
    'email' => [EmailNotification::class, 'Email', 'emailNotificationSettings'],
    'discord' => [DiscordNotification::class, 'Discord', 'discordNotificationSettings'],
    'telegram' => [TelegramNotification::class, 'Telegram', 'telegramNotificationSettings'],
    'slack' => [SlackNotification::class, 'Slack', 'slackNotificationSettings'],
    'pushover' => [PushoverNotification::class, 'Pushover', 'pushoverNotificationSettings'],
    'webhook' => [WebhookNotification::class, 'Webhook', 'webhookNotificationSettings'],
]);

it('lets an admin of the root team turn security alerts off and on', function (string $component, string $channel, string $relation) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->rootTeam]);
    $property = "securityAlert{$channel}Notifications";
    $column = 'security_alert_'.strtolower($channel).'_notifications';

    $test = Livewire::test($component)
        ->assertSet($property, true)
        ->assertSee(__('Security alerts'))
        ->call('toggleEvent', $property)
        ->assertSet($property, false);

    expect($this->rootTeam->{$relation}->fresh()->{$column})->toBeFalse();

    $test->call('toggleEvent', $property)->assertSet($property, true);

    expect($this->rootTeam->{$relation}->fresh()->{$column})->toBeTrue();
})->with('security alert channels');

it('loads the saved security alert setting', function (string $component, string $channel, string $relation) {
    $this->rootTeam->{$relation}->update(['security_alert_'.strtolower($channel).'_notifications' => false]);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test($component)->assertSet("securityAlert{$channel}Notifications", false);
})->with('security alert channels');

it('does not let a member change security alerts', function (string $component, string $channel, string $relation) {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test($component)->call('toggleEvent', "securityAlert{$channel}Notifications");

    expect($this->rootTeam->{$relation}->fresh()->{'security_alert_'.strtolower($channel).'_notifications'})->toBeTrue();
})->with('security alert channels');

it('shows the security alerts section only to the root team', function (string $component) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test($component)->assertDontSee(__('Security alerts'));
})->with([
    'email' => [EmailNotification::class],
    'discord' => [DiscordNotification::class],
    'telegram' => [TelegramNotification::class],
]);

it('saves a telegram forum topic for security alerts', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test(TelegramNotification::class)
        ->assertSee(__('Security alerts topic ID'))
        ->set('telegramNotificationsSecurityAlertThreadId', '99')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->rootTeam->telegramNotificationSettings->fresh()->telegram_notifications_security_alert_thread_id)->toBe('99');
});

it('routes security alerts to their telegram forum topic', function () {
    Queue::fake();
    $this->rootTeam->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'test-token',
        'telegram_chat_id' => '-1001234567890',
        'telegram_notifications_security_alert_thread_id' => '99',
    ]);

    (new TelegramChannel)->send($this->rootTeam->fresh(), new SecurityAlert('account_locked', 'Account locked', ['user_id' => 1]));

    Queue::assertPushed(SendMessageToTelegramJob::class, fn (SendMessageToTelegramJob $job): bool => $job->threadId === '99');
});

it('keeps the security alert topic hidden from serialization', function () {
    $this->rootTeam->telegramNotificationSettings->update(['telegram_notifications_security_alert_thread_id' => '99']);

    expect($this->rootTeam->telegramNotificationSettings->fresh()->toArray())
        ->not->toHaveKey('telegram_notifications_security_alert_thread_id');
});
