<?php

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

/*
 * SSH keys for tests, generated once per test process.
 *
 * Tests never embed a private key: a committed key is a credential for any host that trusts it,
 * and secret scanners reject the push. Pass a different name to get a second, distinct key.
 */

function testSshKeyPair(string $name = 'default'): EC\PrivateKey
{
    static $keys = [];

    return $keys[$name] ??= EC::createKey('Ed25519');
}

/**
 * Ed25519 private key in OpenSSH format.
 */
function testSshPrivateKey(string $name = 'default'): string
{
    return testSshKeyPair($name)->toString('OpenSSH', ['comment' => "coolify-test-{$name}"]);
}

/**
 * The matching public key line, e.g. "ssh-ed25519 AAAA... coolify-test-default".
 */
function testSshPublicKey(string $name = 'default'): string
{
    return testSshKeyPair($name)->getPublicKey()->toString('OpenSSH', ['comment' => "coolify-test-{$name}"]);
}

/**
 * RSA private key in PKCS#1 PEM format ("BEGIN RSA PRIVATE KEY"), as GitHub Apps issue them.
 */
function testRsaPrivateKey(string $name = 'default'): string
{
    static $keys = [];

    return $keys[$name] ??= RSA::createKey(2048)->toString('PKCS1');
}
