<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use App\Support\Services\BaseService;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthService extends BaseService
{
    public function __construct(
        private readonly RegisterTenantAction $registerTenant,
    ) {}

    /** Register a tenant + owner and return {user, token} for immediate login. */
    public function register(RegisterData $data): array
    {
        $user = $this->registerTenant->execute($data);
        $token = auth('api')->login($user);

        return $this->tokenResponse($user, $token);
    }

    /** Attempt credentials; throws a validation error on failure. */
    public function login(string $email, string $password): array
    {
        $token = auth('api')->attempt(['email' => $email, 'password' => $password]);

        if ($token === false) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = auth('api')->user();

        if ($user->status === 'disabled') {
            auth('api')->logout();
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->tokenResponse($user, $token);
    }

    /** Issue a token for a user who authenticated some other way (e.g. mobile OTP). */
    public function loginViaUser(User $user): array
    {
        if ($user->status === 'disabled') {
            throw ValidationException::withMessages([
                'phone' => ['This account has been disabled.'],
            ]);
        }

        $token = auth('api')->login($user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->tokenResponse($user, $token);
    }

    public function logout(): void
    {
        auth('api')->logout();
    }

    public function refresh(): array
    {
        $token = auth('api')->refresh();

        return $this->tokenResponse(auth('api')->user(), $token);
    }

    /** Send a password-reset link. Always reports success to avoid user enumeration. */
    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(array $credentials): void
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }

    private function tokenResponse(User $user, string $token): array
    {
        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ];
    }
}
