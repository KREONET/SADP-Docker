<?php

use Symfony\Component\Process\Process;

function installScriptSection(string $start, string $end): string
{
    $script = file_get_contents(dirname(__DIR__, 2).'/scripts/install.sh');
    $from = strpos($script, $start);
    $to = strpos($script, $end, $from);

    return substr($script, $from, $to - $from);
}

/**
 * Runs the sign-up question and root account defaults without a controlling terminal.
 *
 * @param  array<string, string>  $env
 */
function runInternalSignupSetup(array $env): string
{
    $demo = 'C_BOLD="" C_RESET="" UI_TTY=false'."\n"
        .installScriptSection('# Built-in sign-up or OIDC only', 'UI_INSTALL_STARTED=$(ui_now)')
        .'ROOT_USERNAME=${ROOT_USERNAME:-}'."\n"
        .'ROOT_USER_EMAIL=${ROOT_USER_EMAIL:-}'."\n"
        .'ROOT_USER_PASSWORD=${ROOT_USER_PASSWORD:-}'."\n"
        .installScriptSection('# Without the built-in sign-up, only a root account exists', 'if [ -n "${REGISTRY_URL+x}" ]')
        .installScriptSection('# Random root password', '# Add default root user credentials')
        .'echo "signup=$INTERNAL_SIGNUP user=$ROOT_USERNAME email=$ROOT_USER_EMAIL password=$ROOT_USER_PASSWORD"';

    $process = new Process(['setsid', 'bash', '-c', 'exec 3>/dev/null; '.$demo], env: $env + ['LC_ALL' => 'C']);
    $process->mustRun();

    return trim($process->getOutput());
}

it('keeps the built-in sign-up when nobody answers the question', function () {
    expect(runInternalSignupSetup([]))->toBe('signup=true user= email= password=');
});

it('creates a random root account for OIDC sign-in when the built-in sign-up is off', function () {
    $output = runInternalSignupSetup(['INTERNAL_SIGNUP' => 'false']);

    expect($output)->toStartWith('signup=false user=root email=root@paded.local password=')
        ->and($output)->toMatch('/password=[A-Za-z0-9]{20}Aa1_$/');
});

it('keeps operator supplied root credentials when the built-in sign-up is off', function () {
    $output = runInternalSignupSetup([
        'INTERNAL_SIGNUP' => 'false',
        'ROOT_USERNAME' => 'admin',
        'ROOT_USER_EMAIL' => 'admin@example.org',
        'ROOT_USER_PASSWORD' => 'Given-Passw0rd',
    ]);

    expect($output)->toBe('signup=false user=admin email=admin@example.org password=Given-Passw0rd');
});
