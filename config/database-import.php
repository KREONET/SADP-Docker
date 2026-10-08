<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server paths allowed for database imports
    |--------------------------------------------------------------------------
    |
    | A database import from a file on the server only reads files inside these
    | directories. The Coolify backups directory (/data/coolify/backups) is always
    | allowed. Add more directories as a comma-separated list of absolute paths in
    | DATABASE_IMPORT_ALLOWED_PATHS, for example "/srv/db-dumps,/mnt/backups".
    | The file path is resolved on the server (symbolic links included) before it
    | is checked, so a link inside an allowed directory cannot point outside it.
    | Use canonical directory paths (no symbolic links) here.
    |
    */

    'allowed_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DATABASE_IMPORT_ALLOWED_PATHS', '')),
    ))),

];
