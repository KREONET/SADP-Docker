<?php

use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Server\Security\TerminalAccess;
use App\Livewire\Team\Member;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\SecurityAlert;
use App\Services\TerminalSessionService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    Cache::flush();
    $this->withoutDefer();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->rootTeam->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
    ]);
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

function securityAuditEvents(string $event)
{
    return AuditEvent::query()->where('event', $event)->orderBy('id')->get();
}

function securityTerminalServer(Team $team): Server
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'name' => 'terminal-host',
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_terminal_enabled' => true,
    ]);

    return $server->fresh();
}

describe('notifySecurityAlert', function () {
    it('queues a security alert notification to the root team', function () {
        Notification::fake();

        notifySecurityAlert('failed_login_burst', 'Repeated failed login attempts detected', ['ip' => '192.0.2.1']);

        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, function (SecurityAlert $notification): bool {
            return $notification->type === 'failed_login_burst'
                && $notification->details === ['ip' => '192.0.2.1']
                && $notification->queue === 'high';
        });
        Notification::assertNotSentTo($this->team, SecurityAlert::class);
        expect(new SecurityAlert('x', 'y'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('deduplicates identical alerts for ten minutes', function () {
        Notification::fake();

        notifySecurityAlert('platform_admin_changed', 'Alert', ['member_email' => 'a@example.com', 'count' => 1]);
        notifySecurityAlert('platform_admin_changed', 'Alert', ['member_email' => 'a@example.com', 'count' => 2]);
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 1);

        notifySecurityAlert('platform_admin_changed', 'Alert', ['member_email' => 'b@example.com']);
        notifySecurityAlert('failed_login_burst', 'Alert', ['member_email' => 'a@example.com']);
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 3);

        $this->travel(11)->minutes();
        notifySecurityAlert('platform_admin_changed', 'Alert', ['member_email' => 'a@example.com']);
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 4);
    });

    it('never throws when the root team is missing or sending fails', function () {
        Notification::fake();
        Team::query()->whereKey(0)->delete();

        notifySecurityAlert('test', 'No root team');
        Notification::assertNothingSent();

        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
        expect(fn () => notifySecurityAlert('test', 'Cache down'))->not->toThrow(Throwable::class);
    });

    it('redacts secret-looking context keys in the notification payload', function () {
        $alert = new SecurityAlert('test', 'Alert', ['api_token' => 'abc', 'ip' => '192.0.2.1', 'meta' => ['a' => 1]]);

        expect($alert->details)->toBe(['api_token' => '[REDACTED]', 'ip' => '192.0.2.1', 'meta' => '{"a":1}'])
            ->and($alert->toWebhook())->toMatchArray(['event' => 'security_alert', 'alert_type' => 'test'])
            ->and((string) $alert->toMail()->render())->toContain('192.0.2.1');
    });

    it('sends only on enabled channels whose security alert toggle is on', function () {
        $this->rootTeam->slackNotificationSettings->update([
            'slack_enabled' => true,
            'slack_webhook_url' => 'https://hooks.slack.com/services/abc',
            'security_alert_slack_notifications' => false,
        ]);

        $channels = (new SecurityAlert('test', 'Alert'))->via($this->rootTeam->fresh());

        expect($channels)->toContain(DiscordChannel::class)
            ->not->toContain(SlackChannel::class);
    });
});

describe('failed login burst', function () {
    it('alerts once per window when the threshold is reached', function () {
        Notification::fake();
        config([
            'security.audit.failed_login_alert_threshold' => 3,
            'security.audit.failed_login_alert_window_minutes' => 10,
        ]);

        foreach (range(1, 5) as $attempt) {
            event(new Failed('web', null, ['email' => 'nobody@example.com', 'password' => 'wrong']));
        }

        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'failed_login_burst' && $alert->details['scope'] === 'ip');
        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'failed_login_burst' && $alert->details['scope'] === 'global');
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 2);

        $failed = securityAuditEvents('auth.user.login_failed');
        expect($failed)->toHaveCount(5)
            ->and($failed->first()->metadata['result'])->toBe('failure')
            ->and(json_encode($failed->first()->metadata))->not->toContain('wrong');
    });

    it('does not alert below the threshold', function () {
        Notification::fake();
        config(['security.audit.failed_login_alert_threshold' => 3]);

        event(new Failed('web', null, ['email' => 'nobody@example.com']));
        event(new Failed('web', null, ['email' => 'nobody@example.com']));

        Notification::assertNothingSent();
    });
});

describe('two-factor audit events', function () {
    it('records each fortify two-factor event', function (string $eventClass, string $auditEvent, string $result) {
        $event = $eventClass === RecoveryCodeReplaced::class
            ? new RecoveryCodeReplaced($this->owner, 'secret-recovery-code')
            : new $eventClass($this->owner);

        event($event);

        $record = securityAuditEvents($auditEvent)->sole();
        expect($record->metadata['result'])->toBe($result)
            ->and($record->actor_id)->toBe($this->owner->id)
            ->and(json_encode($record->metadata))->not->toContain('secret-recovery-code');
    })->with([
        [TwoFactorAuthenticationEnabled::class, 'auth.two_factor.enabled', 'success'],
        [TwoFactorAuthenticationConfirmed::class, 'auth.two_factor.confirmed', 'success'],
        [TwoFactorAuthenticationDisabled::class, 'auth.two_factor.disabled', 'success'],
        [RecoveryCodesGenerated::class, 'auth.two_factor.recovery_codes_generated', 'success'],
        [RecoveryCodeReplaced::class, 'auth.two_factor.recovery_code_used', 'success'],
        [ValidTwoFactorAuthenticationCodeProvided::class, 'auth.two_factor.challenge_succeeded', 'success'],
        [TwoFactorAuthenticationFailed::class, 'auth.two_factor.challenge_failed', 'failure'],
    ]);
});

describe('terminal audit events', function () {
    it('audits token issue and successful redemption with server details', function () {
        $server = securityTerminalServer($this->team);
        $token = app(TerminalSessionService::class)->issue($this->owner, $server);

        $this->postJson('/terminal/session', ['token' => $token])->assertSuccessful();

        $issued = securityAuditEvents('terminal.session.issued')->sole();
        $redeemed = securityAuditEvents('terminal.session.redeemed')->sole();

        expect($issued->team_id)->toBe($this->team->id)
            ->and($issued->metadata)->toMatchArray([
                'server_uuid' => $server->uuid,
                'server_name' => 'terminal-host',
                'container' => null,
                'result' => 'success',
            ])
            ->and($issued->actor_id)->toBe($this->owner->id)
            ->and(json_encode($issued->metadata))->not->toContain($token)
            ->and($redeemed->metadata['result'])->toBe('success')
            ->and($redeemed->metadata['command'])->toContain('exec $SHELL')
            ->and(strlen($redeemed->metadata['command']))->toBeLessThanOrEqual(500);
    });

    it('audits denied redemptions', function () {
        $service = app(TerminalSessionService::class);

        expect(fn () => $service->redeem($this->owner, str_repeat('a', 64)))
            ->toThrow(AccessDeniedHttpException::class);

        $denied = securityAuditEvents('terminal.session.redeemed')->sole();
        expect($denied->metadata)->toMatchArray([
            'result' => 'denied',
            'reason' => 'Invalid or expired terminal token.',
        ])->and($denied->level)->toBe('warning');
    });

    it('audits connection requests from the terminal page', function () {
        Process::fake();
        $server = securityTerminalServer($this->team);
        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($server): void {
            $event->route->setParameter('server_uuid', $server->uuid);
        });

        Livewire::test(ExecuteContainerCommand::class)
            ->call('connectToServer')
            ->assertDispatched('send-terminal-command');

        $requested = securityAuditEvents('terminal.connection.requested')->sole();
        expect($requested->metadata)->toMatchArray([
            'server_uuid' => $server->uuid,
            'target_type' => 'server',
            'result' => 'success',
        ]);
    });

    it('audits enabling and disabling terminal access', function () {
        $this->owner->teams()->attach($this->rootTeam, ['role' => 'owner']);
        $server = securityTerminalServer($this->team);

        $component = Livewire::test(TerminalAccess::class, ['server_uuid' => $server->uuid]);
        $component->call('toggleTerminal', 'password');
        $component->call('toggleTerminal', 'password');
        $component->call('toggleTerminal', 'wrong-password');

        $disabled = securityAuditEvents('ui.server.terminal_disabled');
        $enabled = securityAuditEvents('ui.server.terminal_enabled');

        expect($disabled->pluck('metadata.result')->all())->toBe(['success', 'failure'])
            ->and($disabled->first()->metadata)->toMatchArray([
                'server_uuid' => $server->uuid,
                'server_name' => 'terminal-host',
            ])
            ->and($disabled->last()->metadata['reason'])->toBe('password_confirmation_failed')
            ->and($enabled->pluck('metadata.result')->all())->toBe(['success'])
            ->and($enabled->first()->level)->toBe('warning');
    });
});

describe('maskAuditCommand', function () {
    it('masks password and token values and truncates', function () {
        $masked = maskAuditCommand('mysql --password=hunter2 && curl -H "Authorization: Bearer abc123" https://x?token=zzz DB_PASSWORD=pw');

        expect($masked)->not->toContain('hunter2')
            ->not->toContain('abc123')
            ->not->toContain('zzz')
            ->not->toContain('=pw')
            ->toContain('********')
            ->and(maskAuditCommand(str_repeat('a', 900)))->toHaveLength(500)
            ->and(maskAuditCommand(null))->toBeNull();
    });
});

describe('team role changes', function () {
    it('records the previous role on role updates', function () {
        $member = User::factory()->create();
        $member->teams()->attach($this->team, ['role' => 'member']);

        Livewire::test(Member::class, ['member' => $member])->call('makeAdmin');

        $event = securityAuditEvents('ui.team_member.role_updated')->sole();
        expect($event->metadata)->toMatchArray(['previous_role' => 'member', 'role' => 'admin'])
            ->and(securityAuditEvents('platform.admin.granted'))->toHaveCount(0);
    });

    it('audits and alerts platform admin grants and revocations in the root team', function () {
        Notification::fake();
        $this->owner->teams()->attach($this->rootTeam, ['role' => 'owner']);
        session(['currentTeam' => $this->rootTeam]);
        $member = User::factory()->create();
        $member->teams()->attach($this->rootTeam, ['role' => 'member']);

        Livewire::test(Member::class, ['member' => $member])->call('makeAdmin');
        Livewire::test(Member::class, ['member' => $member->fresh()])->call('makeOwner');
        Livewire::test(Member::class, ['member' => $member->fresh()])->call('makeReadonly');

        $granted = securityAuditEvents('platform.admin.granted')->sole();
        $revoked = securityAuditEvents('platform.admin.revoked')->sole();

        expect($granted->team_id)->toBe(0)
            ->and($granted->metadata)->toMatchArray(['previous_role' => 'member', 'role' => 'admin', 'member_id' => $member->id])
            ->and($revoked->metadata)->toMatchArray(['previous_role' => 'owner', 'role' => 'member']);

        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'platform_admin_changed' && $alert->details['action'] === 'granted');
        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'platform_admin_changed' && $alert->details['action'] === 'revoked');
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 2);
    });

    it('treats removing a root team admin as a revocation', function () {
        Notification::fake();
        $this->owner->teams()->attach($this->rootTeam, ['role' => 'owner']);
        session(['currentTeam' => $this->rootTeam]);
        $admin = User::factory()->create();
        $admin->teams()->attach($this->rootTeam, ['role' => 'admin']);

        Livewire::test(Member::class, ['member' => $admin])->call('remove');

        expect(securityAuditEvents('platform.admin.revoked')->sole()->metadata)
            ->toMatchArray(['previous_role' => 'admin', 'role' => null])
            ->and(securityAuditEvents('ui.team_member.removed')->sole()->metadata['previous_role'])->toBe('admin');
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 1);
    });
});
