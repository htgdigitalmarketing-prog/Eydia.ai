<?php

// Laravel front controller for the single-domain layout:
//   <domain>/public_html/index.php  → app lives one level up in  ../app
// Static frontend files sit next to this file and are served directly;
// anything that isn't a real file/dir (e.g. /api/v1/*) comes here.

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__ . '/../app/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__ . '/../app/vendor/autoload.php';

$app = require_once __DIR__ . '/../app/bootstrap/app.php';

$app->handleRequest(Request::capture());
