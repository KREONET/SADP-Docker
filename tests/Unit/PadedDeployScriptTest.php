<?php

use Symfony\Component\Process\Process;

function padedDeployScript(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/scripts/paded-deploy.sh');
}

it('builds the PADED image from the checkout and keeps it across restarts and auto-updates', function () {
    expect(padedDeployScript())
        ->toContain('docker build -f "$REPO_DIR/docker/production/Dockerfile" -t "$IMAGE" "$REPO_DIR"')
        ->toContain('IMAGE="coollabsio/coolify:${IMAGE_TAG}"')
        ->toContain('set_env_var "LATEST_IMAGE" "$IMAGE_TAG"')
        ->toContain('set_env_var "AUTOUPDATE" "false"')
        ->toContain('cp "$REPO_DIR/docker-compose.yml" "$REPO_DIR/docker-compose.prod.yml" "$SOURCE_DIR/"')
        ->toContain('up -d --remove-orphans --wait');
});

it('runs the image the production compose file expects', function () {
    $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.prod.yml');

    expect($compose)->toContain('image: "${REGISTRY_URL:-docker.io}/coollabsio/coolify:${LATEST_IMAGE:-latest}"');
});

it('replaces existing env values and appends missing ones', function () {
    $script = padedDeployScript();
    $from = strpos($script, 'set_env_var() {');
    $function = substr($script, $from, strpos($script, "\n}\n", $from) + 3 - $from);
    $envFile = tempnam(sys_get_temp_dir(), 'paded-env');
    file_put_contents($envFile, "APP_ID=abc\nLATEST_IMAGE=4.3.23\nAUTOUPDATE=\n");

    $process = new Process(['bash', '-c', $function."\nset_env_var LATEST_IMAGE paded\nset_env_var AUTOUPDATE false\nset_env_var REGISTRY_URL docker.io"], env: ['ENV_FILE' => $envFile]);
    $process->mustRun();

    expect(file_get_contents($envFile))->toBe("APP_ID=abc\nLATEST_IMAGE=paded\nAUTOUPDATE=false\nREGISTRY_URL=docker.io\n");
    unlink($envFile);
});

it('is valid bash', function () {
    $process = new Process(['bash', '-n', dirname(__DIR__, 2).'/scripts/paded-deploy.sh']);
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
