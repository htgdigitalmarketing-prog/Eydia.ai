<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin: public website / footer contact details (email, phone, address,
 * social links) shown on the marketing site. Singleton row.
 */
class SiteSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['site_settings' => SiteSetting::current()->toPublicArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'facebook' => ['nullable', 'string', 'max:500'],
            'instagram' => ['nullable', 'string', 'max:500'],
            'youtube' => ['nullable', 'string', 'max:500'],
            'linkedin' => ['nullable', 'string', 'max:500'],
            'ratings' => ['nullable', 'array'],
            'ratings.*.score' => ['nullable', 'string', 'max:8'],
            'ratings.*.count' => ['nullable', 'string', 'max:40'],
        ]);

        $settings = SiteSetting::current();

        // Merge rating edits over the known platforms only (ignore stray keys).
        if (array_key_exists('ratings', $data)) {
            $current = $settings->ratingsWithDefaults();
            $incoming = $data['ratings'] ?? [];
            foreach (array_keys(SiteSetting::DEFAULT_RATINGS) as $key) {
                if (isset($incoming[$key])) {
                    $current[$key] = [
                        'score' => (string) ($incoming[$key]['score'] ?? $current[$key]['score']),
                        'count' => (string) ($incoming[$key]['count'] ?? $current[$key]['count']),
                    ];
                }
            }
            $data['ratings'] = $current;
        }

        $settings->update($data);

        return $this->ok([
            'site_settings' => $settings->fresh()->toPublicArray(),
            'message' => 'Website contact details saved.',
        ]);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
