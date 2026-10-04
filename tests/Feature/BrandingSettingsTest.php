<?php

use App\Livewire\Settings\Index;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const BRANDING_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

const BRANDING_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect width="16" height="16" fill="#123456"/></svg>';

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    Storage::fake('images');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    Server::factory()->create(['id' => 0, 'team_id' => 0]);

    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    $this->rootMember = User::factory()->create();
    $this->rootTeam->members()->attach($this->rootMember->id, ['role' => 'member']);

    $this->tenant = Team::factory()->create();
    $this->tenantOwner = User::factory()->create();
    $this->tenant->members()->attach($this->tenantOwner->id, ['role' => 'owner']);
});

function signInForBranding(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
    Once::flush();
}

function brandingUpload(string $name, string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

it('uses PADED as the default site name and no custom logo', function () {
    expect(brand_name())->toBe('PADED')
        ->and(brand_logo_url())->toBeNull();
});

it('lets platform admins change the site name', function () {
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('brand_name', '  Research Cloud  ')
        ->call('saveBranding')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.index'));

    Once::flush();
    expect(InstanceSettings::get()->brand_name)->toBe('Research Cloud')
        ->and(brand_name())->toBe('Research Cloud');
});

it('falls back to the default name when the site name is cleared', function () {
    InstanceSettings::get()->update(['brand_name' => 'Old Name']);
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('brand_name', '')
        ->call('saveBranding');

    Once::flush();
    expect(InstanceSettings::get()->brand_name)->toBeNull()
        ->and(brand_name())->toBe('PADED');
});

it('rejects site names that are too long or contain markup', function (string $name) {
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('brand_name', $name)
        ->call('saveBranding')
        ->assertHasErrors('brand_name');
})->with([str_repeat('a', 61), '<b>Cloud</b>']);

it('uploads a logo to the images disk and replaces the previous one', function () {
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('logo', brandingUpload('logo.png', BRANDING_PNG))
        ->call('uploadLogo')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.index'));

    Once::flush();
    expect(InstanceSettings::get()->brand_logo_path)->toBe('branding/logo.png');
    Storage::disk('images')->assertExists('branding/logo.png');

    Livewire::test(Index::class)
        ->set('logo', brandingUpload('logo.svg', BRANDING_SVG))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    Once::flush();
    expect(InstanceSettings::get()->brand_logo_path)->toBe('branding/logo.svg')
        ->and(brand_logo_url())->toStartWith(route('branding.logo'));
    Storage::disk('images')->assertExists('branding/logo.svg');
    Storage::disk('images')->assertMissing('branding/logo.png');
});

it('rejects svg logos with scripts or event handlers', function (string $svg) {
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('logo', brandingUpload('logo.svg', $svg))
        ->call('uploadLogo')
        ->assertHasErrors('logo')
        ->assertNoRedirect();

    Once::flush();
    expect(InstanceSettings::get()->brand_logo_path)->toBeNull();
    Storage::disk('images')->assertMissing('branding/logo.svg');
})->with([
    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect/></a></svg>',
]);

it('rejects logo files that are not images', function () {
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('logo', brandingUpload('logo.html', '<html><body>hi</body></html>'))
        ->call('uploadLogo')
        ->assertHasErrors('logo');

    Storage::disk('images')->assertDirectoryEmpty('branding');
});

it('removes the logo', function () {
    Storage::disk('images')->put('branding/logo.png', BRANDING_PNG);
    InstanceSettings::get()->update(['brand_logo_path' => 'branding/logo.png']);
    signInForBranding($this->platformAdmin, $this->rootTeam);

    Livewire::test(Index::class)
        ->call('removeLogo')
        ->assertRedirect(route('settings.index'));

    Once::flush();
    expect(InstanceSettings::get()->brand_logo_path)->toBeNull();
    Storage::disk('images')->assertMissing('branding/logo.png');
});

it('denies branding changes to users who are not platform admins', function (string $who, string $teamProperty) {
    foreach (['saveBranding', 'removeLogo'] as $action) {
        signInForBranding($this->platformAdmin, $this->rootTeam);
        $component = Livewire::test(Index::class)->set('brand_name', 'Hijacked');

        signInForBranding($this->{$who}, $this->{$teamProperty});
        $component->call($action)->assertForbidden();
    }

    Once::flush();
    expect(InstanceSettings::get()->brand_name)->toBeNull();
    $this->get('/settings')->assertForbidden();
})->with([
    'root team member' => ['rootMember', 'rootTeam'],
    'other team owner' => ['tenantOwner', 'tenant'],
]);

it('serves no logo when none is set', function () {
    $this->get('/branding/logo')->assertNotFound();
});

it('serves the logo publicly with hardened headers', function () {
    Storage::disk('images')->put('branding/logo.svg', BRANDING_SVG);
    InstanceSettings::get()->update(['brand_logo_path' => 'branding/logo.svg']);

    $response = $this->get('/branding/logo');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain('sandbox')
        ->and($response->getContent())->toBe(BRANDING_SVG);
});

it('shows the custom name and logo on the login page', function () {
    User::factory()->create();
    Storage::disk('images')->put('branding/logo.png', BRANDING_PNG);
    InstanceSettings::get()->update(['brand_name' => 'Research Cloud', 'brand_logo_path' => 'branding/logo.png']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('<title>Research Cloud</title>', false)
        ->assertSee('Research Cloud')
        ->assertSee(route('branding.logo'), false)
        ->assertDontSee('Kreonet-IAPP');
});

it('uses the custom name in page titles and the navigation bar', function () {
    InstanceSettings::get()->update(['brand_name' => 'Research Cloud']);
    signInForBranding($this->platformAdmin, $this->rootTeam);

    $this->get('/settings')
        ->assertOk()
        ->assertSee('| Research Cloud', false)
        ->assertDontSee('| Coolify', false)
        ->assertDontSee('Kreonet-IAPP');
});
