<?php

return [
    // Forced-update + maintenance switch for the Android app (GET /app/config).
    // Change via env without a deploy.
    'min_supported_version' => env('APP_MIN_SUPPORTED_VERSION', '1.0.0'),
    'latest_version' => env('APP_LATEST_VERSION', '1.0.0'),
    'update_url' => env('APP_UPDATE_URL', 'https://play.google.com/store/apps/details?id=com.heltog.review_flow'),
    'maintenance' => (bool) env('APP_MAINTENANCE', false),
    'maintenance_message' => env('APP_MAINTENANCE_MESSAGE', 'Eydia is undergoing scheduled maintenance. Please try again shortly.'),
];
