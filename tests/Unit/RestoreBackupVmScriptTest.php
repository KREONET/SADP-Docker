<?php

function restoreBackupVmScript(): string
{
    return dirname(__DIR__, 2).'/scripts/restore-backup-vm.sh';
}

it('has valid bash syntax', function () {
    exec('bash -n '.escapeshellarg(restoreBackupVmScript()).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output));
});

it('verifies VM host keys with a dedicated known_hosts file', function () {
    $script = file_get_contents(restoreBackupVmScript());

    expect($script)
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('UserKnownHostsFile=/dev/null')
        ->toContain('KNOWN_HOSTS="$IMAGE_DIR/known_hosts"')
        ->toContain('-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$KNOWN_HOSTS"');
});

it('forgets the key of a recreated VM before connecting to it', function () {
    $script = file_get_contents(restoreBackupVmScript());

    expect($script)
        ->toContain('ssh-keygen -R "$1" -f "$KNOWN_HOSTS"')
        ->toMatch('/VM_IP=\$\(wait_for_ip "\$BUILDER_NAME"\)\n\s*forget_host_key "\$VM_IP"\n\s*wait_for_ssh "\$VM_IP"/')
        ->toMatch('/forget_host_key "\$VM_IP"\nwait_for_ssh "\$VM_IP"/');
});

it('forgets a stale key so ssh accepts the new key of the same address', function () {
    $directory = sys_get_temp_dir().'/restore-vm-known-hosts-'.uniqid();
    mkdir($directory);
    $knownHosts = "{$directory}/known_hosts";
    file_put_contents($knownHosts, "192.168.241.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHbTkfh0p6Yv8Q7SxS7r4M0eEhkS3ZcqvJQ3Hn2qzH3A\n"
        ."192.168.241.11 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHbTkfh0p6Yv8Q7SxS7r4M0eEhkS3ZcqvJQ3Hn2qzH3A\n");

    preg_match('/^forget_host_key\(\) \{.*?^\}/ms', file_get_contents(restoreBackupVmScript()), $matches);
    $function = $matches[0] ?? '';
    expect($function)->not->toBeEmpty();

    exec('bash -c '.escapeshellarg('KNOWN_HOSTS='.escapeshellarg($knownHosts)."\n{$function}\nforget_host_key 192.168.241.10").' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output))
        ->and(file_get_contents($knownHosts))->not->toContain('192.168.241.10 ')
        ->toContain('192.168.241.11 ')
        ->and(file_exists("{$knownHosts}.old"))->toBeFalse();

    array_map('unlink', glob("{$directory}/*") ?: []);
    rmdir($directory);
})->skip(fn (): bool => trim((string) shell_exec('command -v ssh-keygen')) === '', 'ssh-keygen is not installed');

it('prints the PADED start messages on one line', function () {
    $script = file_get_contents(restoreBackupVmScript());

    expect($script)
        ->toContain('log "Restoring the backup and Starting PADED (migrations run on start)"')
        ->toContain('echo "Starting PADED $LATEST_IMAGE..."')
        ->not->toMatch("/PADED\n/");
});
