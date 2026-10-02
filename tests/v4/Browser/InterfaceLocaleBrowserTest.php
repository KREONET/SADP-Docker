<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    Vite::useHotFile(storage_path('framework/browser-test-hot'));
    $this->stack = seedBrowserResourceStack();
    Cache::flush();
});

test('users can switch languages and interact with translated pages', function () {
    $page = visit('/login', ['waitUntil' => 'domcontentloaded'])->withLocale('en-US');

    $page->fill('input[name="email"]', 'test@example.com')
        ->fill('input[name="password"]', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate('/profile', ['waitUntil' => 'domcontentloaded'])
        ->assertSee('Profile details')
        ->click('#locale-trigger')
        ->click('한국어')
        ->assertSee('프로필 정보')
        ->assertAttribute('html[lang]', 'lang', 'ko')
        ->navigate('/platform/logs', ['waitUntil' => 'domcontentloaded'])
        ->assertSee('로그 수집')
        ->assertNoJavaScriptErrors()
        ->navigate('/open-source-licenses', ['waitUntil' => 'domcontentloaded'])
        ->assertSee('오픈 소스 라이선스')
        ->fill('input[aria-label="패키지 검색"]', 'laravel/framework')
        ->assertSee('laravel/framework')
        ->assertDontSee('aws/aws-sdk-php')
        ->click('button[wire\\:click^="toggle("]')
        ->assertSee('The MIT License')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'interface-korean-licenses')
        ->navigate('/profile', ['waitUntil' => 'domcontentloaded'])
        ->assertSee('프로필 정보')
        ->click('#locale-trigger')
        ->click('English')
        ->assertSee('Profile details')
        ->assertAttribute('html[lang]', 'lang', 'en')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'interface-english-profile');

    expect($this->stack['user']->fresh()->locale)->toBe('en');
});

test('the login page uses the browser language', function () {
    $page = visit('/login', ['waitUntil' => 'domcontentloaded'])->withLocale('ko-KR');
    $page->assertSee('비밀번호를 잊으셨나요?');
    $page->assertAttribute('html[lang]', 'lang', 'ko');
    $page->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'interface-korean-login');
});
