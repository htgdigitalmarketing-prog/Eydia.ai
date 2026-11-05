<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mobile-only auth helpers. None of these change the web flows.
 *   • web-session / mobile-login — a one-time signed hand-off so the app can open
 *     the web billing page already signed in (Google Play forbids in-app digital
 *     payments, so purchases happen on the web).
 *   • account — account deletion (a Google Play requirement).
 */
class MobileAuthController extends Controller
{
    /** Paths the app is allowed to deep-link into after the hand-off. */
    private const NEXT_WHITELIST = ['/billing', '/plans', '/buy-credits'];

    /** Issue a one-time code the app opens in the browser to land signed in. */
    public function webSession(Request $request): JsonResponse
    {
        $data = $request->validate(['next' => ['sometimes', 'nullable', 'string', 'max:120']]);

        $code = Str::random(48);
        Cache::put('mobile_login:' . $code, [
            'user_id' => $request->user()->id,
            'next' => $this->whitelistNext($data['next'] ?? null),
        ], now()->addSeconds(60));

        return $this->ok([
            'login_url' => rtrim((string) config('app.url'), '/') . '/api/v1/auth/mobile-login/' . $code,
            'expires_in' => 60,
        ]);
    }

    /** Public landing for the one-time code → redirect to the web app, signed in. */
    public function mobileLogin(string $code): RedirectResponse
    {
        $entry = Cache::pull('mobile_login:' . $code); // single use
        $userId = $entry['user_id'] ?? null;
        $user = $userId ? User::withoutGlobalScopes()->find($userId) : null;

        if (! $user) {
            return redirect($this->frontend('/login', ['error' => 'link_expired']));
        }

        // Do NOT call auth('api')->logout() here: login() set this token on the
        // guard, so logout() would blacklist the very token we just issued and the
        // web app would receive a dead (401) token. The guard is request-scoped,
        // so nothing persists between requests anyway.
        $token = auth('api')->login($user);

        return redirect($this->frontend('/auth/callback', [
            'token' => $token,
            'next' => $entry['next'] ?? '/billing',
        ]));
    }

    /** Delete the signed-in user's account (and tenant when they are the last member). */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        // Google-only users confirm with a fresh ID token; everyone else with
        // their current password.
        if ($user->google_id && ! $request->filled('password')) {
            $data = $request->validate(['id_token' => ['required', 'string']]);
            $res = Http::acceptJson()->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $data['id_token']]);
            $email = $res->successful() ? ($res->json('email') ?? null) : null;
            if (! $email || strcasecmp($email, (string) $user->email) !== 0) {
                return $this->fail('Could not verify your Google identity. Please try again.', [], 422, 'token_invalid');
            }
        } else {
            $data = $request->validate(['password' => ['required', 'string']]);
            if (! $user->password || ! Hash::check($data['password'], $user->password)) {
                return $this->fail('Current password is incorrect.', [], 422);
            }
        }

        $tenantId = $user->tenant_id;
        $othersLeft = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $user->id)
            ->exists();

        auth('api')->logout();
        $user->delete(); // soft delete — keeps records for tax/audit

        // Last member of the workspace → soft-delete the tenant too. Invoices and
        // payment records are retained (separate rows, soft-deleted tenant).
        if (! $othersLeft && $tenantId) {
            Tenant::withoutGlobalScopes()->where('id', $tenantId)->update(['status' => 'deleted']);
            Tenant::withoutGlobalScopes()->where('id', $tenantId)->delete();
        }

        return $this->ok(['message' => 'Your account has been deleted. Invoices are retained as required by law.']);
    }

    private function whitelistNext(?string $next): string
    {
        $next = $next ? '/' . ltrim($next, '/') : '';

        return in_array($next, self::NEXT_WHITELIST, true) ? $next : '/billing';
    }

    private function frontend(string $path, array $query = []): string
    {
        $base = rtrim((string) (config('services.google.frontend_url') ?: config('app.url')), '/');
        $qs = $query ? '?' . http_build_query($query) : '';

        return $base . $path . $qs;
    }
}
