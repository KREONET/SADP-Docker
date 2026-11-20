<?php

use App\Services\OpenSourceLicenses;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/coolify-licenses-'.bin2hex(random_bytes(4));
    $write = function (string $path, string $content): void {
        if (! is_dir(dirname($this->root.'/'.$path))) {
            mkdir(dirname($this->root.'/'.$path), 0777, true);
        }
        file_put_contents($this->root.'/'.$path, $content);
    };

    $write('composer.lock', json_encode([
        'packages' => [
            ['name' => 'acme/http', 'version' => 'v2.1.0', 'license' => ['MIT'], 'homepage' => 'https://acme.test/http'],
            ['name' => 'acme/no-license-file', 'version' => '1.0.0', 'license' => ['BSD-3-Clause'], 'source' => ['url' => 'https://github.com/acme/no-license-file.git']],
        ],
        'packages-dev' => [
            ['name' => 'acme/test-runner', 'version' => '9.0.0', 'license' => ['MIT']],
        ],
    ]));
    $write('vendor/acme/http/LICENSE', "MIT License\n\nCopyright (c) Acme");
    mkdir($this->root.'/vendor/acme/no-license-file', 0777, true);

    $write('package-lock.json', json_encode(['packages' => [
        '' => ['name' => 'coolify'],
        'node_modules/chart-lib' => ['version' => '3.0.0', 'license' => '(MIT OR Apache-2.0)'],
        'node_modules/vite' => ['version' => '8.0.0', 'license' => 'MIT', 'dev' => true],
        'node_modules/tailwindcss' => ['version' => '4.3.3', 'license' => 'MIT', 'dev' => true],
        'node_modules/chart-lib/node_modules/tiny' => ['version' => '1.0.0', 'license' => 'ISC'],
    ]]));
    $write('node_modules/chart-lib/LICENSE-MIT.txt', 'chart-lib license');
    $write('node_modules/chart-lib/package.json', json_encode(['repository' => ['url' => 'git+https://github.com/acme/chart-lib.git']]));
    $write('node_modules/tailwindcss/LICENSE', 'tailwind license');

    $write('docker/coolify-terminal/package-lock.json', json_encode(['packages' => [
        'node_modules/ws' => ['version' => '8.21.3', 'license' => 'MIT'],
    ]]));
    $write('docker/coolify-terminal/node_modules/ws/LICENSE', 'ws license');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

test('the inventory lists shipped packages with their license texts', function () {
    $packages = collect(OpenSourceLicenses::generate($this->root)['packages'])->keyBy('key');

    expect($packages->keys()->all())->toBe([
        'panel:acme/http',
        'panel:acme/no-license-file',
        'terminal:ws',
        'web-ui:chart-lib',
        'web-ui:tailwindcss',
        'web-ui:tiny',
    ])
        ->and($packages['panel:acme/http'])->toMatchArray([
            'version' => '2.1.0',
            'licenses' => ['MIT'],
            'homepage' => 'https://acme.test/http',
            'license_text' => "MIT License\n\nCopyright (c) Acme",
        ])
        ->and($packages['panel:acme/no-license-file'])->toMatchArray([
            'homepage' => 'https://github.com/acme/no-license-file',
            'license_text' => null,
        ])
        ->and($packages['web-ui:chart-lib'])->toMatchArray([
            'licenses' => ['MIT', 'Apache-2.0'],
            'homepage' => 'https://github.com/acme/chart-lib',
            'license_text' => 'chart-lib license',
        ])
        ->and($packages['web-ui:tiny']['homepage'])->toBe('https://www.npmjs.com/package/tiny')
        ->and($packages['terminal:ws']['license_text'])->toBe('ws license');
});

test('development-only packages are not listed', function () {
    $keys = collect(OpenSourceLicenses::generate($this->root)['packages'])->pluck('key');

    expect($keys)->not->toContain('panel:acme/test-runner')
        ->and($keys)->not->toContain('web-ui:vite')
        // tailwindcss is a devDependency, but its CSS ships in the build.
        ->and($keys)->toContain('web-ui:tailwindcss');
});

test('the expected versions come from the lock files alone', function () {
    expect(OpenSourceLicenses::expectedVersions($this->root))->toBe([
        'panel:acme/http' => '2.1.0',
        'panel:acme/no-license-file' => '1.0.0',
        'terminal:ws' => '8.21.3',
        'web-ui:chart-lib' => '3.0.0',
        'web-ui:tailwindcss' => '4.3.3',
        'web-ui:tiny' => '1.0.0',
    ]);
});
