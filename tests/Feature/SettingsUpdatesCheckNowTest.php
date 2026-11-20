<?php

use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    @unlink(storage_path('app/versions.json'));
});

beforeEach(function () {
    Queue::fake([CheckTraefikVersionJob::class]);
    config()->set('constants.coolify.version', '4.4.6');

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);
});

it('rejects the removed manual update action without contacting the CDN', function () {
    Http::fake();

    expect(fn () => Livewire::test(Updates::class)->call('checkManually'))->toThrow(MethodNotFoundException::class);

    Http::assertNothingSent();
});

it('refreshes component metadata without advertising a newer panel version', function () {
    config()->set('constants.coolify.self_hosted', true);
    Http::fake(['*' => Http::response(['stable' => ['coolify' => ['version' => '4.4.7']]])]);

    expect((new CheckForUpdatesJob)->handle())->toBeTrue()
        ->and((bool) InstanceSettings::get()->new_version_available)->toBeFalse();
    Queue::assertPushed(CheckTraefikVersionJob::class);
});
