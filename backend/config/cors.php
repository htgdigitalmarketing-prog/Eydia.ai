<?php

return [
    // API + auth endpoints are called cross-origin by the Next.js app.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Frontend dev origins. Add your production domain here later.
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // JWT is sent as a Bearer header (not cookies), so credentials aren't needed.
    'supports_credentials' => false,
];
