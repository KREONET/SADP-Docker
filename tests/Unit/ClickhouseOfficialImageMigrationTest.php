<?php

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use Tests\TestCase;

uses(TestCase::class);

test('clickhouse uses clickhouse_db field in internal connection string', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'testuser';
    $clickhouse->clickhouse_admin_password = 'testpass';
    $clickhouse->clickhouse_db = 'mydb';
    $clickhouse->uuid = 'test-uuid';

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)
        ->toContain('mydb')
        ->toContain('testuser')
        ->toContain('test-uuid');
});

test('clickhouse defaults to default database when clickhouse_db is null', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'testuser';
    $clickhouse->clickhouse_admin_password = 'testpass';
    $clickhouse->clickhouse_db = null;
    $clickhouse->uuid = 'test-uuid';

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)->toContain('/default');
});

test('clickhouse external url uses correct database', function () {
    $clickhouse = new StandaloneClickhouse;
    $clickhouse->clickhouse_admin_user = 'admin';
    $clickhouse->clickhouse_admin_password = 'secret';
    $clickhouse->clickhouse_db = 'production';
    $clickhouse->uuid = 'prod-uuid';
    $clickhouse->is_public = true;
    $clickhouse->public_port = 8123;

    $server = (new Server)->forceFill(['ip' => '1.2.3.4']);
    $server->setRelation('settings', new ServerSetting);
    $clickhouse->setRelation('destination', (new StandaloneDocker)->setRelation('server', $server));
    $externalUrl = $clickhouse->external_db_url;

    expect($externalUrl)->toContain('production')
        ->toContain('@prod-uuid.1.2.3.4.sslip.io:8123/');

});
