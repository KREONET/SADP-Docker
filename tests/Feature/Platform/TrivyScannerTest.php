<?php

use App\Models\InstanceSettings;
use App\Services\Security\TrivyScanner;

test('severities are parsed, validated and defaulted', function (?string $raw, array $expected) {
    expect(TrivyScanner::parseSeverities($raw))->toBe($expected);
})->with([
    ['critical, high', ['CRITICAL', 'HIGH']],
    ['MEDIUM;bogus', ['MEDIUM']],
    ['', ['HIGH', 'CRITICAL']],
    [null, ['HIGH', 'CRITICAL']],
]);

test('the scan command streams the checkout into a trivy container', function () {
    $scanner = new TrivyScanner('aquasec/trivy:0.58.1', ['CRITICAL', 'HIGH'], ignoreUnfixed: true);

    $command = $scanner->scanCommand('/artifacts/abc123');

    expect($command)
        ->toStartWith("tar -C '/artifacts/abc123' --exclude=.git -cf - . | docker run --rm -i")
        ->toContain("--entrypoint sh 'aquasec/trivy:0.58.1'")
        ->toContain('-v coolify-trivy-cache:/root/.cache/trivy')
        ->toContain('--severity CRITICAL,HIGH')
        ->toContain('--ignore-unfixed')
        ->toContain(TrivyScanner::COMPLETION_MARKER);
});

test('the scan command rejects unsafe source directories and images', function () {
    $scanner = new TrivyScanner('aquasec/trivy:latest', ['HIGH'], false);

    expect(fn () => $scanner->scanCommand('/artifacts/abc;rm -rf /'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $scanner->scanCommand('relative/path'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TrivyScanner('aquasec/trivy;id', ['HIGH'], false))->toThrow(InvalidArgumentException::class);
});

test('scanner settings come from instance settings', function () {
    $settings = new InstanceSettings([
        'trivy_image' => 'registry.example.com/aquasec/trivy:0.58.1',
        'trivy_severities' => 'CRITICAL',
        'trivy_ignore_unfixed' => false,
    ]);

    $scanner = TrivyScanner::fromSettings($settings);

    expect($scanner->severities())->toBe(['CRITICAL'])
        ->and($scanner->scanCommand('/artifacts/x'))->toContain("'registry.example.com/aquasec/trivy:0.58.1'")
        ->not->toContain('--ignore-unfixed');
});

test('findings are parsed from json lines and sorted by severity', function () {
    $scanner = new TrivyScanner('aquasec/trivy:latest', ['HIGH', 'CRITICAL'], false);
    $output = implode("\n", [
        '2026-09-30T10:00:00Z INFO Downloading DB...',
        json_encode(['target' => 'package-lock.json', 'id' => 'CVE-2024-0002', 'pkg' => 'lodash', 'installed' => '4.17.20', 'fixed' => '4.17.21', 'severity' => 'HIGH', 'title' => 'Prototype pollution', 'url' => 'https://avd.aquasec.com/nvd/cve-2024-0002']),
        json_encode(['target' => 'go.sum', 'id' => 'CVE-2024-0001', 'pkg' => 'golang.org/x/net', 'installed' => '0.1.0', 'fixed' => '', 'severity' => 'CRITICAL', 'title' => null, 'url' => '']),
        '{not json',
        TrivyScanner::COMPLETION_MARKER,
    ]);

    $report = $scanner->parseOutput($output);

    expect($report->completed)->toBeTrue()
        ->and($report->hasFindings())->toBeTrue()
        ->and($report->findings)->toHaveCount(2)
        ->and($report->findings[0]['id'])->toBe('CVE-2024-0001')
        ->and($report->findings[1]['package'])->toBe('lodash')
        ->and($report->counts())->toBe(['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0, 'unknown' => 0])
        ->and($report->summary())->toBe('CRITICAL: 1, HIGH: 1');
});

test('a clean scan completes without findings', function () {
    $report = (new TrivyScanner('aquasec/trivy:latest', ['HIGH'], false))->parseOutput(TrivyScanner::COMPLETION_MARKER."\n");

    expect($report->completed)->toBeTrue()->and($report->hasFindings())->toBeFalse();
});

test('output without the completion marker is a failed scan', function () {
    $report = (new TrivyScanner('aquasec/trivy:latest', ['HIGH'], false))->parseOutput("FATAL failed to download vulnerability DB\n");

    expect($report->completed)->toBeFalse()
        ->and($report->error)->toContain('failed to download vulnerability DB');
});
