<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

/**
 * Consistent API envelope used by every controller.
 * Success: { "success": true, "data": ... }
 * Error:   { "success": false, "message": "...", "errors": {...} }
 */
trait ApiResponse
{
    protected function ok($data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    protected function fail(string $message, array $errors = [], int $status = 400, ?string $code = null): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ];
        // Stable, machine-readable error code for native clients (the web app
        // ignores it). e.g. plan_required, feature_not_included,
        // credits_insufficient, tenant_suspended, token_expired, token_invalid.
        if ($code !== null) {
            $body['code'] = $code;
        }

        return response()->json($body, $status);
    }
}
