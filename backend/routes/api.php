<?php

use Illuminate\Support\Facades\Route;

/*
| API v1. Modules register their route groups here as they are built.
| /auth is public (self-contained); everything under the tenant group sits
| behind auth:api + the EnsureTenant middleware.
*/

// Meta/Razorpay webhooks live in routes/webhooks.php and are deliberately not throttled.
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/health', fn() => response()->json(['success' => true, 'data' => ['status' => 'ok']]));

    // Key-guarded post-deploy hook (no SSH needed): clears caches + migrates.
    Route::match(['get', 'post'], '/system/deploy', [\App\Modules\System\Controllers\SystemController::class, 'deploy']);

    // Public media endpoint (UUID is unguessable, safe without auth)
    Route::get('/whatsapp/media/{uuid}', [\App\Modules\WhatsApp\Controllers\InboxController::class, 'media'])
        ->middleware('throttle:60,1');

    // Public pricing for the marketing landing page (no auth).
    Route::get('/public/plans', [\App\Modules\Billing\Controllers\PublicPlansController::class, 'index'])
        ->middleware('throttle:60,1');

    // Auth module (public + auth:api-protected endpoints).
    require __DIR__ . '/modules/auth.php';

    // Authenticated, tenant-scoped module routes mount here.
    Route::middleware(['auth:api', 'tenant'])->group(function () {
        Route::get('/ping', fn() => response()->json([
            'success' => true,
            'data' => ['pong' => true, 'tenant_id' => auth()->user()->tenant_id],
        ]));

        // Reachable without an active plan, so a tenant can buy one or ask for help.
        require __DIR__ . '/modules/billing.php';
        require __DIR__ . '/modules/support.php';
        require __DIR__ . '/modules/admin.php';
        require __DIR__ . '/modules/devices.php';

        Route::middleware('plan')->group(function () {
            require __DIR__ . '/modules/assistant.php';
            require __DIR__ . '/modules/whatsapp.php';
            require __DIR__ . '/modules/contacts.php';
            require __DIR__ . '/modules/templates.php';
            require __DIR__ . '/modules/analytics.php';
            require __DIR__ . '/modules/campaigns.php';
            require __DIR__ . '/modules/automations.php';
            require __DIR__ . '/modules/chatbot.php';
            require __DIR__ . '/modules/agents.php';
            require __DIR__ . '/modules/team.php';
            require __DIR__ . '/modules/social.php';

            // JayVisionX unified dashboards (plan-feature gated inside each file).
            require __DIR__ . '/modules/leads.php';
            require __DIR__ . '/modules/gbp.php';
            require __DIR__ . '/modules/seo.php';
            require __DIR__ . '/modules/ads.php';
            require __DIR__ . '/modules/invoicing.php';
        });
    });
});
