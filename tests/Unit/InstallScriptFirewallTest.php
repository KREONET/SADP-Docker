<?php

use Symfony\Component\Process\Process;

/**
 * Runs the installer's firewall block with stubbed docker, sshd, ufw, and firewall-cmd commands
 * that record their arguments, and returns those calls. ufw and firewall-cmd default to failing
 * stubs so the host firewall is never touched.
 *
 * @param  array<string, string>  $env
 */
function runInstallerFirewallBlock(string $stubs, array $env = []): string
{
    $script = file_get_contents(dirname(__DIR__, 2).'/scripts/install.sh');
    $from = strpos($script, '# Coolify manages this server over SSH from its container');
    $block = substr($script, $from, strpos($script, 'step_done', $from) - $from);

    $demo = <<<BASH
        warn() { echo "WARN \$*"; }
        ufw() { return 1; }
        firewall-cmd() { return 1; }
        docker() { echo "172.19.0.0/16 "; }
        sshd() { printf 'port 2204\nlistenaddress 0.0.0.0:2204\n'; }
        {$stubs}
        {$block}
        BASH;

    $process = new Process(['bash', '-c', $demo], env: $env + ['LC_ALL' => 'C']);
    $process->mustRun();

    return $process->getOutput();
}

it('allows SSH from the Coolify network through an active UFW', function () {
    $output = runInstallerFirewallBlock(<<<'BASH'
        ufw() { if [ "$1" = status ]; then echo "Status: active"; else echo "UFW $*"; fi; }
        BASH);

    expect($output)->toContain('UFW allow from 172.19.0.0/16 to any port 2204 proto tcp comment Coolify container to host SSH')
        ->toContain('allowed SSH (port 2204) from the Coolify network 172.19.0.0/16');
});

it('adds a firewalld rich rule when firewalld is running', function () {
    $output = runInstallerFirewallBlock(<<<'BASH'
        firewall-cmd() { [ "$1" = --state ] && return 0; echo "FIREWALLD $*"; }
        BASH);

    expect($output)->toContain('FIREWALLD --permanent --add-rich-rule=rule family=ipv4 source address=172.19.0.0/16 port port=2204 protocol=tcp accept')
        ->toContain('FIREWALLD --reload');
});

it('leaves the firewall alone when UFW is inactive or the operator opts out', function (string $stubs, array $env) {
    expect(runInstallerFirewallBlock($stubs, $env))->not->toContain('UFW allow');
})->with([
    'inactive ufw' => ['ufw() { if [ "$1" = status ]; then echo "Status: inactive"; else echo "UFW $*"; fi; }', []],
    'opted out' => ['ufw() { if [ "$1" = status ]; then echo "Status: active"; else echo "UFW $*"; fi; }', ['CONFIGURE_FIREWALL' => 'false']],
]);
