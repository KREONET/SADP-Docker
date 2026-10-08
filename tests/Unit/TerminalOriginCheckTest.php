<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('passes the terminal origin check unit tests', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('Node.js is not installed.');
    }

    $process = new Process([$node, '--test', 'terminal-origin.test.js'], dirname(__DIR__, 2).'/docker/coolify-terminal');
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

it('checks the Origin header before authenticating terminal websocket upgrades', function () {
    $server = file_get_contents(dirname(__DIR__, 2).'/docker/coolify-terminal/terminal-server.js');

    $originCheck = strpos($server, 'checkTerminalOrigin({ origin: req.headers.origin, host: req.headers.host }, allowedTerminalOrigins)');
    $authentication = strpos($server, 'await authenticateTerminalUpgrade(sessionCookie, postToCoolify)');

    expect($server)->toContain("import { checkTerminalOrigin, getAllowedTerminalOrigins } from './terminal-origin.js';")
        ->and($originCheck)->not->toBeFalse()
        ->and($authentication)->not->toBeFalse()
        ->and($originCheck)->toBeLessThan($authentication);
});
