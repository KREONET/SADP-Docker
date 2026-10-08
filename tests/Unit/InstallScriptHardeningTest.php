<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function hardeningScript(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

function hardeningSection(string $script, string $start, string $end): string
{
    $from = strpos($script, $start);
    expect($from)->not->toBeFalse();
    $to = strpos($script, $end, $from + strlen($start));
    expect($to)->not->toBeFalse();

    return substr($script, $from, $to - $from);
}

/**
 * Runs a script fragment against a scratch .env and returns [output, .env contents, backup contents].
 *
 * @param  array<string, string>  $env
 * @return array{0: string, 1: string, 2: string|null}
 */
function runInstallerFragment(string $fragment, string $envFile, array $env = [], ?string $backup = null): array
{
    $directory = sys_get_temp_dir().'/coolify-installer-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("$directory/.env", $envFile);
    if ($backup !== null) {
        file_put_contents("$directory/.env-20260101", $backup);
    }

    $demo = "set -e\nset -o pipefail\nENV_FILE=$directory/.env\nDATE=20260101\nwarn() { echo \"WARN \$*\"; }\nsleep() { :; }\n".$fragment;
    $process = new Process(['bash', '-c', $demo], $directory, $env + ['LC_ALL' => 'C']);
    $process->mustRun();

    $result = [$process->getOutput(), file_get_contents("$directory/.env"), $backup !== null ? file_get_contents("$directory/.env-20260101") : null];
    (new Process(['rm', '-rf', $directory]))->run();

    return $result;
}

dataset('install scripts', [
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
]);

it('writes COOLIFY_BIND_ADDRESS and APP_URL and marks https session cookies secure', function (string $path) {
    $script = hardeningScript($path);
    $fragment = hardeningSection($script, 'update_env_var() {', 'update_env_var "APP_ID"')
        .hardeningSection($script, '# Direct ports (8000, 6001, 6002)', 'if [ "$DOCKER_POOL_BASE_PROVIDED" = true ]')
        .'echo "bind=$COOLIFY_BIND_ADDRESS"';

    [$output, $envFile] = runInstallerFragment($fragment, "APP_ID=x\nCOOLIFY_BIND_ADDRESS=0.0.0.0\n", [
        'COOLIFY_BIND_ADDRESS' => '127.0.0.1',
        'APP_URL' => 'https://coolify.example.com/',
    ]);

    expect($output)->toContain('bind=127.0.0.1')
        ->and($envFile)->toContain("COOLIFY_BIND_ADDRESS=127.0.0.1\n")
        ->not->toContain('COOLIFY_BIND_ADDRESS=0.0.0.0')
        ->toContain("APP_URL=https://coolify.example.com\n")
        ->toContain("SESSION_SECURE_COOKIE=true\n");
})->with('install scripts');

it('keeps the defaults and ignores invalid values', function (string $path) {
    $script = hardeningScript($path);
    $fragment = hardeningSection($script, 'update_env_var() {', 'update_env_var "APP_ID"')
        .hardeningSection($script, '# Direct ports (8000, 6001, 6002)', 'if [ "$DOCKER_POOL_BASE_PROVIDED" = true ]')
        .'echo "bind=$COOLIFY_BIND_ADDRESS"';

    [$output, $envFile] = runInstallerFragment($fragment, "APP_ID=x\nSESSION_SECURE_COOKIE=false\n");
    expect($output)->toContain('bind=')
        ->and($envFile)->toBe("APP_ID=x\nSESSION_SECURE_COOKIE=false\n");

    [$output, $envFile] = runInstallerFragment($fragment, "APP_ID=x\n", [
        'COOLIFY_BIND_ADDRESS' => '127.0.0.1; rm -rf /',
        'APP_URL' => 'https://x.example.com|evil',
    ]);
    expect($output)->toContain('WARN Ignoring invalid COOLIFY_BIND_ADDRESS')
        ->toContain('WARN Ignoring invalid APP_URL')
        ->and($envFile)->toBe("APP_ID=x\n");
})->with('install scripts');

it('removes the plaintext root password from .env and its backup once the root user exists', function (string $path) {
    $fragment = 'docker() { echo " 1 "; }'."\n"
        .hardeningSection(hardeningScript($path), '# The root user is created from ROOT_USER_PASSWORD', "\nfi\n")."\nfi\n";

    [$output, $envFile, $backup] = runInstallerFragment(
        $fragment,
        "ROOT_USER_EMAIL=root@example.com\nROOT_USER_PASSWORD=Secret-Passw0rd_\nDB_USERNAME=coolify\n",
        backup: "ROOT_USER_PASSWORD=Old-Passw0rd_\n",
    );

    expect($envFile)->toBe("ROOT_USER_EMAIL=root@example.com\nROOT_USER_PASSWORD=\nDB_USERNAME=coolify\n")
        ->and($backup)->toBe("ROOT_USER_PASSWORD=\n")
        ->and($output)->not->toContain('Passw0rd');
})->with('install scripts');

it('keeps the root password when the root user was not created', function (string $path) {
    $fragment = 'docker() { echo "0"; }'."\n"
        .hardeningSection(hardeningScript($path), '# The root user is created from ROOT_USER_PASSWORD', "\nfi\n")."\nfi\n";

    [$output, $envFile] = runInstallerFragment($fragment, "ROOT_USER_PASSWORD=Secret-Passw0rd_\n");

    expect($envFile)->toBe("ROOT_USER_PASSWORD=Secret-Passw0rd_\n")
        ->and($output)->toContain('WARN The root user was not created yet');
})->with('install scripts');

it('prints a generated root password from memory instead of reading it back from .env', function () {
    expect(hardeningScript('scripts/install.sh'))
        ->toContain('"$C_BOLD" "$ROOT_USER_PASSWORD" "$C_RESET"')
        ->not->toContain("grep '^ROOT_USER_PASSWORD='");
});

it('binds the direct ports to COOLIFY_BIND_ADDRESS only when it is set', function (string $composeFile) {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/'.$composeFile);

    expect($compose['services']['coolify']['ports'])->toBe([
        '${COOLIFY_BIND_ADDRESS:+${COOLIFY_BIND_ADDRESS}:}${APP_PORT:-8000}:8080',
        '${COOLIFY_BIND_ADDRESS:+${COOLIFY_BIND_ADDRESS}:}${REVERB_PORT:-${SOKETI_PORT:-6001}}:6001',
        '${COOLIFY_BIND_ADDRESS:+${COOLIFY_BIND_ADDRESS}:}6002:6002',
    ]);
})->with([
    'stable' => 'docker-compose.prod.yml',
    'nightly' => 'other/nightly/docker-compose.prod.yml',
]);

it('hides the nginx and PHP versions at server level', function () {
    $site = file_get_contents(dirname(__DIR__, 2).'/docker/production/etc/nginx/site-opts.d/http.conf');
    $http = file_get_contents(dirname(__DIR__, 2).'/docker/production/etc/nginx/conf.d/custom.conf');

    expect($site)->toContain("server_tokens off;\n")
        ->toContain("fastcgi_hide_header X-Powered-By;\n")
        // The base image already sets server_tokens in the http block; repeating it there fails nginx -t.
        ->and($http)->not->toContain('server_tokens');
});
