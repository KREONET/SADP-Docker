<?php

use App\Models\Server;
use App\Services\Swarm\SwarmCommands;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
    $this->server->user = 'ubuntu';
});

afterEach(function () {
    Mockery::close();
});

/**
 * Runs a script with stub commands that log their arguments (and stdin) instead of acting.
 *
 * @param  list<string>  $commands
 * @return array{log: string, stdin: array<string, string>, home: string}
 */
function runSwarmScriptWithStubs(array $commands): array
{
    $directory = sys_get_temp_dir().'/swarm-commands-'.bin2hex(random_bytes(4));
    $bin = $directory.'/bin';
    $home = $directory.'/home';
    mkdir($bin, recursive: true);
    mkdir($home);

    $stub = <<<'SH'
#!/bin/sh
name=$(basename "$0")
echo "$name $*" >> "$STUB_DIR/log"
case "$name $*" in
    *"--password-stdin"*) cat > "$STUB_DIR/login-stdin" ;;
    tee*) cat > "$STUB_DIR/tee-stdin" ;;
esac
exit 0
SH;
    foreach (['docker', 'mkdir', 'tee', 'chown', 'chmod'] as $command) {
        file_put_contents("{$bin}/{$command}", $stub);
        chmod("{$bin}/{$command}", 0755);
    }
    file_put_contents("{$bin}/sudo", "#!/bin/sh\nexec \"\$@\"\n");
    chmod("{$bin}/sudo", 0755);

    try {
        $process = new Process(['/bin/bash', '-c', implode("\n", $commands)], env: [
            'PATH' => $bin.':'.getenv('PATH'),
            'HOME' => $home,
            'STUB_DIR' => $directory,
        ]);
        $process->mustRun();

        $read = fn (string $file): string => is_file("{$directory}/{$file}") ? (string) file_get_contents("{$directory}/{$file}") : '';

        return [
            'log' => $read('log'),
            'stdin' => [
                'login' => $read('login-stdin'),
                'tee' => $read('tee-stdin'),
            ],
            'home' => $home,
        ];
    } finally {
        (new Process(['rm', '-rf', $directory]))->run();
    }
}

test('join quotes the token and addresses and targets the manager port', function () {
    expect(SwarmCommands::join('SWMTKN-1-abc', '10.0.0.1', '10.0.0.2'))
        ->toBe("docker swarm join --token 'SWMTKN-1-abc' --advertise-addr '10.0.0.2' '10.0.0.1:2377'")
        ->and(SwarmCommands::join("token'; rm -rf /", '10.0.0.1'))
        ->toBe("docker swarm join --token 'token'\\''; rm -rf /' '10.0.0.1:2377'");
});

test('init, drain and remove quote their arguments', function () {
    expect(SwarmCommands::init('10.0.0.1'))->toBe("docker swarm init --advertise-addr '10.0.0.1'")
        ->and(SwarmCommands::drainNode('abc123'))->toBe("docker node update --availability drain 'abc123'")
        ->and(SwarmCommands::removeNode('abc123'))->toBe("docker node rm --force 'abc123'");
});

test('the registry runs on the manager node with password authentication', function () {
    $commands = SwarmCommands::setUpRegistry('node-1', 'secret-password');
    $script = implode("\n", $commands);

    expect($script)
        ->toContain("--constraint 'node.id==node-1'")
        ->toContain('--publish published=5000,target=5000')
        ->toContain('REGISTRY_AUTH=htpasswd')
        ->toContain('registry:2.8.3')
        ->toContain('docker --config "$HOME/.docker" login 127.0.0.1:5000 --username coolify --password-stdin');

    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d/", $script, $matches);
    [$username, $hash] = explode(':', trim(base64_decode($matches[1])), 2);

    expect($username)->toBe('coolify')
        ->and(password_verify('secret-password', $hash))->toBeTrue()
        ->and(base64_decode($matches[1]))->not->toContain('secret-password');
});

test('the registry setup works for non-root SSH users after sudo is added', function () {
    $commands = parseCommandsByLineForSudo(collect(SwarmCommands::setUpRegistry('node-1', 'secret-password')), $this->server);

    $result = runSwarmScriptWithStubs($commands);

    expect($result['log'])
        ->toContain('mkdir -p /data/coolify/swarm-registry/data')
        ->toContain('tee /data/coolify/swarm-registry/auth/htpasswd')
        ->toContain('docker service create --detach --name coolify-registry --constraint node.id==node-1')
        ->toContain("docker --config {$result['home']}/.docker login 127.0.0.1:5000 --username coolify --password-stdin")
        ->and(trim($result['stdin']['login']))->toBe('secret-password');

    [, $hash] = explode(':', trim($result['stdin']['tee']), 2);
    expect(password_verify('secret-password', $hash))->toBeTrue();
});

test('cluster commands keep working for non-root SSH users', function () {
    $commands = parseCommandsByLineForSudo(collect([
        SwarmCommands::listNodes(),
        SwarmCommands::removeProxyStack(),
        ...SwarmCommands::removeRegistry(),
        SwarmCommands::leave(force: true),
    ]), $this->server);

    $result = runSwarmScriptWithStubs($commands);

    expect($result['log'])
        ->toContain('docker node ls -q')
        ->toContain('docker node inspect --format')
        ->toContain('docker stack rm coolify-proxy')
        ->toContain('docker service rm coolify-registry')
        ->toContain("docker --config {$result['home']}/.docker logout 127.0.0.1:5000")
        ->toContain('docker swarm leave --force');
});
