<?php

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);

it('runs loki and grafana only in the observability profile of the dev stack', function () use ($root) {
    $services = Yaml::parseFile($root.'/docker-compose.dev-multi.yml')['services'];

    expect($services['loki']['profiles'])->toBe(['observability'])
        ->and($services['grafana']['profiles'])->toBe(['observability'])
        ->and($services['grafana']['ports'][0])->toContain('${FORWARD_GRAFANA_PORT:-3000}:3000')
        ->and($services['grafana']['volumes'])->toContain('./docker/development/grafana/provisioning:/etc/grafana/provisioning:ro')
        ->and($services['loki'])->not->toHaveKey('ports');
});

it('provisions the loki data source that the coolify logs dashboard uses', function () use ($root) {
    $provisioning = $root.'/docker/development/grafana/provisioning';
    $datasource = Yaml::parseFile($provisioning.'/datasources/loki.yaml')['datasources'][0];
    $dashboard = json_decode(file_get_contents($provisioning.'/dashboards/coolify-logs.json'), true, flags: JSON_THROW_ON_ERROR);

    $panelDatasources = collect($dashboard['panels'])->pluck('datasource.uid')->unique()->values()->all();
    $expressions = collect($dashboard['panels'])->pluck('targets.0.expr')->implode("\n");

    expect($datasource)->toMatchArray(['type' => 'loki', 'uid' => 'coolify-loki', 'url' => 'http://loki:3100'])
        ->and($panelDatasources)->toBe(['coolify-loki'])
        ->and($expressions)->toContain('source="audit"')->toContain('source="system"')->toContain('source="container"');
});
