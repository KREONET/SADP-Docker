<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\InterfaceLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create(['name' => 'Member']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

function signInWithLocale(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
}

function useDefaultLocale(string $locale): void
{
    InstanceSettings::query()->find(0)->update(['default_locale' => $locale]);
    Once::flush();
}

test('the login page follows the browser language before sign-in', function () {
    $this->get('/login', ['Accept-Language' => 'ko-KR,ko;q=0.9,en;q=0.8'])
        ->assertOk()
        ->assertSee('lang="ko"', false)
        ->assertSee('비밀번호를 잊으셨나요?');

    $this->get('/login', ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertSee('lang="en"', false)
        ->assertSee('Forgot password?');
});

test('without a supported browser language the instance default is used', function () {
    useDefaultLocale('ko');

    $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertSee('lang="ko"', false);
    $this->get('/login', ['Accept-Language' => ''])->assertSee('lang="ko"', false);
    // A supported language later in the list still wins over the default.
    $this->get('/login', ['Accept-Language' => 'fr-FR,en;q=0.5'])->assertSee('lang="en"', false);
});

test('a signed-in user gets their own language, else the instance default', function () {
    useDefaultLocale('ko');
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);

    expect(InterfaceLocale::forRequest($request, $this->member))->toBe('ko');

    $this->member->update(['locale' => 'en']);
    expect(InterfaceLocale::forRequest($request, $this->member->fresh()))->toBe('en');

    $this->member->forceFill(['locale' => 'xx'])->save();
    expect(InterfaceLocale::forRequest($request, $this->member->fresh()))->toBe('ko');
});

test('pages render in the language of the signed-in user', function () {
    $this->member->update(['locale' => 'ko']);
    signInWithLocale($this->member, $this->team);

    $this->get('/open-source-licenses')->assertOk()->assertSee('lang="ko"', false);
    expect(now()->subMinutes(5)->diffForHumans())->toContain('전');
});

test('users can choose their language in the profile', function () {
    signInWithLocale($this->member, $this->team);

    Livewire::test(ProfileIndex::class)
        ->assertSet('locale', 'default')
        ->set('locale', 'ko')
        ->call('updateLocale')
        ->assertRedirect(route('profile'));
    expect($this->member->fresh()->locale)->toBe('ko');

    Livewire::test(ProfileIndex::class)
        ->assertSet('locale', 'ko')
        ->set('locale', 'default')
        ->call('updateLocale');
    expect($this->member->fresh()->locale)->toBeNull();
});

test('only available languages can be chosen', function () {
    signInWithLocale($this->member, $this->team);

    Livewire::test(ProfileIndex::class)
        ->set('locale', 'xx')
        ->call('updateLocale')
        ->assertHasErrors('locale');

    expect($this->member->fresh()->locale)->toBeNull();
});

test('changing the profile language only changes the signed-in user', function () {
    $other = User::factory()->create(['name' => 'Other']);
    $this->team->members()->attach($other->id, ['role' => 'member']);
    signInWithLocale($this->member, $this->team);

    Livewire::test(ProfileIndex::class)->set('locale', 'ko')->call('updateLocale');

    expect($other->fresh()->locale)->toBeNull();
});

test('platform admins can set the instance default language', function () {
    signInWithLocale($this->admin, $this->rootTeam);

    Livewire::test(SettingsIndex::class)
        ->set('default_locale', 'ko')
        ->call('updateDefaultLocale')
        ->assertRedirect(route('settings.index'));

    expect(InstanceSettings::query()->find(0)->default_locale)->toBe('ko');

    Livewire::test(SettingsIndex::class)
        ->set('default_locale', 'xx')
        ->call('updateDefaultLocale')
        ->assertHasErrors('default_locale');
});

test('other users cannot change the instance default language', function () {
    expect(Gate::forUser($this->member)->allows('update', InstanceSettings::query()->find(0)))->toBeFalse();

    signInWithLocale($this->member, $this->team);
    Livewire::test(SettingsIndex::class)->assertRedirect(route('dashboard'));

    expect(InstanceSettings::query()->find(0)->default_locale)->toBe('en');
});
