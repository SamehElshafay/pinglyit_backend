<?php

return [

    // HS256 signing secret — generate with: php -r "echo bin2hex(random_bytes(32));"
    'secret' => env('JWT_SECRET'),

    'algo' => 'HS256',

    // Minutes a token stays valid before the client must log in again.
    'ttl' => (int) env('JWT_TTL', 60 * 24),

];
