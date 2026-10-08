<?php

use App\Services\Ssh\KnownHosts;

if (! function_exists('gitSshHostKeyOptions')) {
    /**
     * SSH options for git over SSH: host key verification against a persistent
     * known_hosts file (accept-new) and the FIPS approved algorithms. The string
     * has no surrounding spaces and contains no quotes, so it can be embedded in
     * a double-quoted GIT_SSH_COMMAND.
     */
    function gitSshHostKeyOptions(): string
    {
        return KnownHosts::gitSshOptions();
    }
}
