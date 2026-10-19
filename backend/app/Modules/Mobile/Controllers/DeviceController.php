<?php

namespace App\Modules\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile push-notification device registration. The React Native app calls
 * /devices on login (and when the push token changes) so the server can notify
 * the right phones about new WhatsApp messages. Tenant-scoped automatically.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = DeviceToken::where('user_id', $request->user()->id)
            ->orderByDesc('last_used_at')
            ->get(['uuid', 'platform', 'provider', 'last_used_at']);

        return $this->ok(['devices' => $devices]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['nullable', 'in:expo,fcm'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ]);

        // One row per token; re-registering just refreshes its owner and usage.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $request->user()->id,
                'provider' => $data['provider'] ?? 'expo',
                'platform' => $data['platform'] ?? 'android',
                'last_used_at' => now(),
            ],
        );

        return $this->ok(['device' => ['id' => $device->uuid]], 201);
    }

    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return $this->ok(['message' => 'Device unregistered.']);
    }
}
