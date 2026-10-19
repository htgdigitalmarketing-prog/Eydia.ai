<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Super admins bypass every policy/permission check. This is the single
        // audited seam through which tenant isolation is intentionally lifted.
        Gate::before(function (User $user, string $ability) {
            return $user->is_super_admin ? true : null;
        });

        // Spatie answers permission checks from a cached copy of the role tables.
        // If that copy is stale (e.g. cache files outliving a deploy), every check
        // fails even though the DB grants the permission — trust the DB and rebuild.
        Gate::after(function (User $user, string $ability, ?bool $result) {
            if ($result !== null || ! str_contains($ability, '.')) {
                return null;
            }
            if (! $user->getAllPermissions()->contains('name', $ability)) {
                return null;
            }

            static $flushed = false;
            if (! $flushed) {
                $flushed = true;
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            return true;
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
