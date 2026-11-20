<?php

use App\Services\Logging\ContainerLogParser;
use Carbon\CarbonImmutable;

it('parses docker timestamps with nanoseconds and offsets as utc', function () {
    $lines = ContainerLogParser::parse(implode("\n", [
        '2026-10-02T09:30:00.123456789Z Server listening on :3000',
        '2026-10-02T18:30:01+09:00 offset line',
    ]));

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['logged_at']->format('Y-m-d H:i:s.u'))->toBe('2026-10-02 09:30:00.123456')
        ->and($lines[0]['message'])->toBe('Server listening on :3000')
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[1]['logged_at']->format('Y-m-d H:i:s'))->toBe('2026-10-02 09:30:01');
});

it('skips lines already stored by the previous run', function () {
    $after = CarbonImmutable::parse('2026-10-02T09:30:00.500000Z');

    $lines = ContainerLogParser::parse(implode("\n", [
        '2026-10-02T09:30:00.400000000Z old',
        '2026-10-02T09:30:00.500000000Z boundary',
        '2026-10-02T09:30:00.600000000Z new',
    ]), $after);

    expect(array_column($lines, 'message'))->toBe(['new']);
});

it('ignores docker errors, empty lines and lines without timestamps', function () {
    $lines = ContainerLogParser::parse(implode("\n", [
        'Error response from daemon: No such container: web-1',
        '',
        '2026-10-02T09:30:00.000000000Z ',
        '2026-10-02T09:30:00.000000000Z kept',
    ]));

    expect(array_column($lines, 'message'))->toBe(['kept']);
});

it('removes ansi colors', function () {
    $lines = ContainerLogParser::parse("2026-10-02T09:30:00Z \e[31mred\e[0m text");

    expect($lines[0]['message'])->toBe('red text');
});

it('detects the level from the message', function (string $message, string $level) {
    expect(ContainerLogParser::detectLevel($message))->toBe($level);
})->with([
    ['PANIC: out of memory', 'critical'],
    ['Error: connect ECONNREFUSED', 'error'],
    ['Unhandled exception in worker', 'error'],
    ['[WARN] cache miss ratio high', 'warning'],
    ['GET /health 200', 'info'],
    ['debug: tick', 'debug'],
]);
