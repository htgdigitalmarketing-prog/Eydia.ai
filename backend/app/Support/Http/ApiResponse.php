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

    protected function fail(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }
}
