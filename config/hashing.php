<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. PBKDF2-HMAC-SHA256 (FIPS 140-3 / KCMVP
    | approved) is the default; its iteration count is read from
    | config('security.password.pbkdf2_iterations'). Legacy bcrypt hashes
    | still verify and are upgraded to PBKDF2 on the user's next sign-in.
    |
    | Supported: "pbkdf2", "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'pbkdf2'),

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | When enabled, a password hash created with an outdated algorithm or cost
    | (e.g. bcrypt, or fewer PBKDF2 iterations) is replaced after a successful
    | password sign-in.
    |
    */

    'rehash_on_login' => true,

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Bcrypt algorithm. This will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Argon algorithm. These will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
    ],

];
