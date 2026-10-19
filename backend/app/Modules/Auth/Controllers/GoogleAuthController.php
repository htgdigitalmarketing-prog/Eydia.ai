<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" — a manual OAuth 2.0 flow (no extra package).
 *
 * redirect()  → sends the browser to Google's consent screen.
 * callback()  → exchanges the code, finds-or-creates the user (auto-provisioning
 *               a workspace for a brand-new Google email), issues a JWT, and
 *               bounces back to the frontend with ?token=... .
 *
 * Activates as soon as GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI
 * are set; until then it redirects back with a friendly error.
 */
class GoogleAuthController extends Controller
{
    public function __construct(private readonly RegisterTenantAction $register) {}

    private const SCOPES = 'openid email profile';

    public function redirect(): RedirectResponse
    {
        $clientId = config('services.google.client_id');
        $redirect = config('services.google.redirect');

        if (! $clientId || ! $redirect) {
            return redirect($this->frontend('/login', ['google_error' => 'not_configured']));
        }

        $state = Str::random(40);
        Cache::put('google_oauth_state:' . $state, true, 600);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error') || ! $request->filled('code')) {
            return redirect($this->frontend('/login', ['google_error' => 'cancelled']));
        }

        $state = $request->query('state');
        if (! $state || ! Cache::pull('google_oauth_state:' . $state)) {
            return redirect($this->frontend('/login', ['google_error' => 'invalid_state']));
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirect = config('services.google.redirect');

        // Exchange the authorization code for tokens.
        $tokenRes = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->string('code'),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenRes->failed()) {
            return redirect($this->frontend('/login', ['google_error' => 'token_exchange_failed']));
        }

        $accessToken = $tokenRes->json('access_token');
        $refreshToken = $tokenRes->json('refresh_token');

        // Fetch the Google profile.
        $profileRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        if ($profileRes->failed()) {
            return redirect($this->frontend('/login', ['google_error' => 'profile_failed']));
        }

        $profile = $profileRes->json();
        $email = $profile['email'] ?? null;
        if (! $email) {
            return redirect($this->frontend('/login', ['google_error' => 'no_email']));
        }

        $user = $this->findOrCreateUser($profile, $refreshToken);

        if ($user->status === 'disabled') {
            return redirect($this->frontend('/login', ['google_error' => 'account_disabled']));
        }

        $token = auth('api')->login($user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect($this->frontend('/auth/callback', ['token' => $token]));
    }

    /** Match an existing user by Google id or email; otherwise provision a tenant. */
    private function findOrCreateUser(array $profile, ?string $refreshToken): User
    {
        $email = $profile['email'];
        $name = $profile['name'] ?? Str::before($email, '@');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'google_id' => $profile['sub'] ?? $user->google_id,
                'avatar_url' => $user->avatar_url ?: ($profile['picture'] ?? null),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            if ($refreshToken) {
                $user->google_refresh_token = $refreshToken; // keep for future Google Business Profile use
            }
            $user->saveQuietly();

            return $user;
        }

        // Brand-new Google user → provision a workspace with a random password.
        $user = $this->register->execute(new RegisterData(
            companyName: $name . "'s workspace",
            name: $name,
            email: $email,
            password: Str::random(32),
        ));

        $user->forceFill([
            'google_id' => $profile['sub'] ?? null,
            'google_refresh_token' => $refreshToken,
            'avatar_url' => $profile['picture'] ?? null,
            'email_verified_at' => now(),
        ])->saveQuietly();

        // Restore super-admin auth context cleared by the provisioning action.
        auth('api')->logout();

        return $user;
    }

    /** Build a frontend URL with query params. */
    private function frontend(string $path, array $query = []): string
    {
        $base = rtrim((string) config('services.google.frontend_url'), '/');
        $qs = $query ? '?' . http_build_query($query) : '';

        return $base . $path . '/' . $qs;
    }
}
