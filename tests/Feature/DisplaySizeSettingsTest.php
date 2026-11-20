<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

/**
 * 화면 크기(밀도·글자 크기) 설정 테스트.
 *
 * 크기 자체는 브라우저의 CSS 가 정하므로 여기서는 (1) 설정 화면이 선택지를 모두 그리는지,
 * (2) 페이지가 그려지기 전에 설정을 적용하는 코드가 레이아웃에 있는지,
 * (3) 토큰 파일이 "촘촘하게 = 이전 디자인 크기" 약속을 지키는지를 확인합니다.
 */
beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['name' => 'Display User']);
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('the appearance page offers every control size and text size', function () {
    $html = $this->get(route('profile.appearance'))->assertOk()->getContent();

    foreach (['Display size', 'Control size', 'Text size', 'Reset to default', 'Auto', 'Compact', 'Comfortable', 'Touch', 'Small', 'Normal', 'Large', 'Extra large'] as $label) {
        expect($html)->toContain($label);
    }

    // 선택지마다 해당 밀도로 미리보기를 그리고, 클릭하면 저장하는 함수를 호출합니다.
    foreach (['auto', 'compact', 'comfortable', 'touch'] as $density) {
        expect($html)->toContain("setDensity('{$density}')");
    }
    foreach (['small', 'normal', 'large', 'xlarge'] as $size) {
        expect($html)->toContain("setTextSize('{$size}')");
    }
    expect($html)->toContain('data-density="compact"')->toContain('data-density="touch"');
});

test('every page applies the saved display settings before it is painted', function () {
    $html = $this->get(route('profile.appearance'))->getContent();

    expect($html)
        ->toContain('window.applyUiPreferences')
        ->toContain('localStorage.uiDensity')
        ->toContain('localStorage.uiTextSize')
        ->toContain("matchMedia('(pointer: coarse)')");
});

test('the display settings reach guests too so the login page is readable', function () {
    auth()->logout();

    $this->get(route('login'))->assertOk()->assertSee('window.applyUiPreferences', false);
});

test('the compact density keeps the sizes of the previous design', function () {
    $css = file_get_contents(resource_path('css/design-tokens.css'));
    preg_match('/\[data-density="compact"\]\s*\{(.*?)\}/s', $css, $compact);

    expect($compact[1] ?? '')
        ->toContain('--ui-h-sm: 1.75rem')  // 이전 h-7
        ->toContain('--ui-h-md: 2rem')     // 이전 h-8 (버튼·입력 32px)
        ->toContain('--ui-h-lg: 2.25rem')  // 이전 h-9
        ->toContain('--ui-h-xl: 2.5rem');  // 이전 h-10
});

test('the touch density makes ordinary controls at least 44px tall', function () {
    $css = file_get_contents(resource_path('css/design-tokens.css'));
    preg_match('/\[data-density="touch"\]\s*\{(.*?)\}/s', $css, $touch);
    preg_match('/--ui-h-md:\s*([\d.]+)rem/', $touch[1] ?? '', $height);

    expect((float) ($height[1] ?? 0) * 16)->toBeGreaterThanOrEqual(44);
});

test('shared control styles read the density tokens instead of fixed heights', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    preg_match('/@utility button \{(.*?)\n\}/s', $utilities, $button);
    expect($button[1] ?? '')
        ->toContain('height: var(--ui-h-md)')
        ->not->toMatch('/\bh-8\b/');

    preg_match('/@utility input-select \{(.*?)\n\}/s', $utilities, $input);
    expect($input[1] ?? '')->toContain('height: var(--ui-h-md)');
});

test('important text size classes are overridden inside a layer, otherwise the browser ignores them', function () {
    $css = file_get_contents(resource_path('css/design-tokens.css'));

    // 레이어 밖의 !important 는 Tailwind 의 레이어 안 !important 에 지므로 base 레이어 안에 있어야 합니다.
    preg_match('/@layer base \{(.*)\}\s*$/s', $css, $layer);

    expect($layer[1] ?? '')->toContain('.text-\[12px\]\!')->toContain('!important');
    expect(preg_replace('/@layer base \{.*\}\s*$/s', '', $css))->not->toContain('\! {');
});
