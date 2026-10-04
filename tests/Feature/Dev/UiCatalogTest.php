<?php

use App\Livewire\Dev\UiCatalog;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * UI 컴포넌트 카탈로그(/dev/ui) 테스트.
 * 개발자가 믿고 복사해 쓰는 화면이라, 열리는지와 "개발 환경에서만 열리는지"를 확인합니다.
 */
beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['name' => 'Catalog User']);
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
});

test('guests are sent to the login page', function () {
    $this->get('/dev/ui')->assertRedirect('/login');
});

test('the catalog shows every component group with a copyable code example', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->get('/dev/ui')
        ->assertOk()
        ->assertSee('UI 컴포넌트 카탈로그')
        ->assertSee('디자인 토큰')
        ->assertSee('버튼')
        ->assertSee('입력 / 선택 / 체크박스')
        ->assertSee('표 (data-table)')
        ->assertSee('확인 창 (modal-confirmation)')
        ->assertSee('토스트(알림)')
        ->assertSee('색 토큰')
        // 코드 예제는 Blade 로 해석되지 않고 글자 그대로 보여야 합니다.
        ->assertSee('&lt;x-forms.button type=&quot;button&quot; wire:click=&quot;save&quot;&gt;', false);
});

test('the catalog is not available in production', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->app['env'] = 'production';

    // 라우트가 등록되어 있어도 컴포넌트가 한 번 더 막습니다.
    Livewire::test(UiCatalog::class)->assertNotFound();
});

test('the toast demo only sends known toast types', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(UiCatalog::class)
        ->call('showToast', 'success')
        ->assertDispatched('success')
        ->call('showToast', 'error')
        ->assertDispatched('error');

    Livewire::test(UiCatalog::class)->call('showToast', 'drop-table')->assertNotFound();
});

test('the demo confirmation shows a toast', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(UiCatalog::class)->call('confirmDemo')->assertDispatched('success');
});

test('demo inputs bind to the component so the examples really work', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(UiCatalog::class)
        ->assertSet('demoName', 'my-application')
        ->set('demoName', 'renamed')
        ->set('demoEnabled', false)
        ->assertSet('demoName', 'renamed')
        ->assertSet('demoEnabled', false);
});
