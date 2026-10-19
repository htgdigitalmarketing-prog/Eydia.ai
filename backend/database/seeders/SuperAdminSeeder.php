<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps a single platform super admin (tenant_id null). Credentials come
 * from env so no secret is committed; falls back to a documented default in
 * local only.
 *
 * Password behaviour: when SUPER_ADMIN_PASSWORD is set, it is synced on EVERY
 * run — so changing .env and re-running the seeder resets the password. When it
 * is not set, the local default is only applied on first creation (an existing
 * account's password is never clobbered with the default).
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL', 'admin@example.com');

        $admin = User::withoutGlobalScopes()->firstOrNew(['email' => $email, 'tenant_id' => null]);
        $admin->forceFill([
            'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
            'is_super_admin' => true,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $password = env('SUPER_ADMIN_PASSWORD');
        if ($password !== null && $password !== '') {
            $admin->password = Hash::make($password); // explicit env value → always sync
        } elseif (!$admin->exists) {
            $admin->password = Hash::make('password'); // local default, only on first create
        }

        $admin->save();
        $admin->assignRole('super-admin');
    }
}
