<?php

namespace App\Modules\Mobile\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public app config for the mobile client: the minimum supported version (for a
 * forced-update prompt), the latest version, the store URL and a maintenance
 * switch. Values come from env so they can change without a deploy.
 */
class AppConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'min_supported_version' => (string) config('mobile.min_supported_version'),
            'latest_version' => (string) config('mobile.latest_version'),
            'update_url' => (string) config('mobile.update_url'),
            'maintenance' => (bool) config('mobile.maintenance'),
            'maintenance_message' => (string) config('mobile.maintenance_message'),
        ]);
    }
}
