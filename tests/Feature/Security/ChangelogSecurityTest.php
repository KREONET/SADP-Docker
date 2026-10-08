<?php

use App\Jobs\PullChangelog;
use App\Livewire\SettingsDropdown;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\ChangelogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

afterEach(function () {
    File::delete(base_path('changelogs/1998-02.json'));
});

function setChangelogEnabled(bool $enabled): void
{
    InstanceSettings::query()->whereKey(0)->update(['is_changelog_enabled' => $enabled]);
    Once::flush();
}

function writeMaliciousChangelog(): void
{
    File::ensureDirectoryExists(base_path('changelogs'));
    File::put(base_path('changelogs/1998-02.json'), json_encode([
        'entries' => [[
            'tag_name' => 'v0.0.1-xss',
            'title' => 'Malicious release',
            'content' => "## Notes\n\n<img src=x onerror=alert(1)>\n\n<script>alert(2)</script>\n\n[click](javascript:alert(3))\n\n<div x-data x-init=\"alert(4)\">alpine</div>",
            'published_at' => '1998-02-01T00:00:00Z',
        ]],
    ]));
}

it('does not fetch the changelog when it is disabled', function () {
    setChangelogEnabled(false);
    Http::fake();

    (new PullChangelog)->handle();

    Http::assertNothingSent();
});

it('fetches the changelog when it is enabled', function () {
    setChangelogEnabled(true);
    config(['constants.coolify.releases_url' => 'https://example.test/releases.json']);
    Http::fake(['https://example.test/releases.json' => Http::response([], 200)]);

    (new PullChangelog)->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/releases.json');
});

it('returns no entries and no unread count when the changelog is disabled', function () {
    writeMaliciousChangelog();
    setChangelogEnabled(false);
    $user = User::factory()->create();
    $service = app(ChangelogService::class);

    expect($service->isEnabled())->toBeFalse()
        ->and($service->getEntries(1000))->toBeEmpty()
        ->and($service->getAllEntries())->toBeEmpty()
        ->and($service->getUnreadCountForUser($user))->toBe(0);
});

it('escapes raw html and drops unsafe links when rendering release notes', function () {
    $html = app(ChangelogService::class)->parseMarkdown(
        "## Fixed\n\n<img src=x onerror=alert(1)>\n\n<script>alert(2)</script>\n\n[click](javascript:alert(3)) and **bold** [docs](https://example.com/docs)\n\n<div x-data x-init=\"alert(4)\">alpine</div>"
    );

    expect($html)
        ->not->toContain('<img')
        ->not->toContain('<script')
        ->not->toContain('<div')
        ->not->toContain('javascript:')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->toContain('&lt;script&gt;')
        ->toContain('<strong')
        ->toContain('href="https://example.com/docs"')
        ->toContain('<h2');
});

it('sanitizes html even if it bypasses the markdown renderer', function () {
    $html = app(ChangelogService::class)->sanitizeHtml(
        '<p class="x" onclick="alert(1)">hi</p><a href="javascript:alert(1)">x</a><iframe src="https://evil.test"></iframe><span x-on:click="alert(1)" style="color:#fff">c</span>'
    );

    expect($html)
        ->toContain('<p class="x">hi</p>')
        ->toContain('style="color:#fff;"')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe')
        ->not->toContain('x-on:click');
});

it('renders sanitized changelog entries in the whats new modal', function () {
    writeMaliciousChangelog();
    setChangelogEnabled(true);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(SettingsDropdown::class, ['trigger' => 'account-menu'])
        ->call('openWhatsNewModal')
        ->assertSet('showWhatsNewModal', true)
        ->assertSee('Malicious release');

    $entry = $component->instance()->entries->firstWhere('tag_name', 'v0.0.1-xss');

    expect($entry->content_html)
        ->not->toContain('<img')
        ->not->toContain('<script')
        ->not->toContain('<div')
        ->not->toContain('javascript:');
});

it('hides the whats new entry point when the changelog is disabled', function () {
    setChangelogEnabled(false);
    $this->actingAs(User::factory()->create());

    Livewire::test(SettingsDropdown::class, ['trigger' => 'account-menu'])
        ->assertDontSee("What's New")
        ->call('openWhatsNewModal')
        ->assertSet('showWhatsNewModal', false)
        ->assertDontSee("What's new");
});
