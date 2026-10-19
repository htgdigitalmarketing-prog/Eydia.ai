<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Global roles + permissions (guard: api). Roles are shared definitions;
 * assignment happens per user. Super Admin implicitly gets everything via a
 * Gate::before in Phase 4, but we still create the role for clarity.
 */
class RolePermissionSeeder extends Seeder
{
    /** Domain => actions. Expands to "domain.action" permission names. */
    private array $matrix = [
        'contacts'      => ['view', 'create', 'update', 'delete', 'import', 'export', 'assign'],
        'conversations' => ['view', 'reply', 'assign', 'note', 'tag', 'status', 'close'],
        'templates'     => ['view', 'create', 'sync', 'submit', 'delete'],
        'campaigns'     => ['view', 'create', 'update', 'delete', 'start', 'pause', 'cancel'],
        'workflows'     => ['view', 'create', 'update', 'delete', 'activate'],
        'bots'          => ['view', 'create', 'update', 'delete', 'activate'],
        'agents'        => ['view', 'create', 'update', 'delete'],
        'tags'          => ['view', 'create', 'update', 'delete'],
        'analytics'     => ['view'],
        'whatsapp'      => ['view', 'connect', 'manage'],
        'billing'       => ['view', 'manage'],
        'team'          => ['view', 'invite', 'update', 'remove'],
        'settings'      => ['view', 'manage'],
    ];

    public function run(): void
    {
        $all = [];
        foreach ($this->matrix as $domain => $actions) {
            foreach ($actions as $action) {
                $name = "{$domain}.{$action}";
                Permission::findOrCreate($name, 'api');
                $all[] = $name;
            }
        }

        $owner = Role::findOrCreate('tenant-owner', 'api');
        $owner->syncPermissions($all); // owner gets every tenant permission

        $manager = Role::findOrCreate('manager', 'api');
        $manager->syncPermissions(array_values(array_filter($all, fn ($p) =>
            ! in_array($p, ['billing.manage', 'settings.manage', 'whatsapp.connect', 'team.remove'], true)
        )));

        $agent = Role::findOrCreate('agent', 'api');
        $agent->syncPermissions([
            'conversations.view', 'conversations.reply', 'conversations.note',
            'conversations.tag', 'conversations.status',
            'contacts.view', 'contacts.update',
            'templates.view', 'analytics.view',
        ]);

        // Platform-level role; permissions bypassed by super-admin gate in Phase 4.
        Role::findOrCreate('super-admin', 'api');

        Artisan::call('permission:cache-reset');
    }
}
