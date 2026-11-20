<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot, key-guarded post-deploy hook for shared hosting with no SSH.
 *
 * Visiting /api/v1/system/deploy?key=DEPLOY_KEY runs a FIXED, safe set of
 * artisan commands — migrate + clear stale route/config caches — so freshly
 * uploaded code (new routes, new tables) actually takes effect. It can run NO
 * arbitrary command. Disabled entirely unless DEPLOY_KEY is set in .env.
 */
class SystemController extends Controller
{
    public function deploy(Request $request): JsonResponse
    {
        $expected = $this->deployKey();

        if (! $expected) {
            return $this->fail('Deploy hook is disabled. Set DEPLOY_KEY in .env to enable it.', [], 403);
        }
        $key = $request->header('X-Deploy-Key') ?: (string) $request->query('key');
        if (! hash_equals($expected, $key)) {
            return $this->fail('Invalid deploy key.', [], 403);
        }

        $output = [];

        // Clear stale caches FIRST so the new routes/config are picked up, then
        // apply any pending migrations.
        foreach (['optimize:clear', 'migrate'] as $command) {
            $params = $command === 'migrate' ? ['--force' => true] : [];
            try {
                Artisan::call($command, $params);
                $output[$command] = trim(Artisan::output());
            } catch (\Throwable $e) {
                $output[$command] = 'ERROR: ' . $e->getMessage();
            }
        }

        // Run the essential bootstrap seeders (idempotent — safe to re-run):
        // roles/permissions and the platform super admin. This makes a fresh
        // no-SSH deploy fully self-serve. Demo data is intentionally NOT seeded.
        $seeders = [
            \Database\Seeders\RolePermissionSeeder::class,
            \Database\Seeders\CreditPackSeeder::class,
            \Database\Seeders\SuperAdminSeeder::class,
        ];
        // Seed the default plans ONLY on a fresh install (empty plans table). Once
        // any plan exists we never run PlanSeeder again, so plans created or edited
        // in the admin panel are never reset on deploy.
        if (\App\Models\Plan::withTrashed()->count() === 0) {
            array_unshift($seeders, \Database\Seeders\PlanSeeder::class);
        } else {
            $output['seed:PlanSeeder'] = 'skipped (plans already exist — kept as-is)';
        }
        foreach ($seeders as $seeder) {
            try {
                Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                $output['seed:' . class_basename($seeder)] = trim(Artisan::output()) ?: 'done';
            } catch (\Throwable $e) {
                $output['seed:' . class_basename($seeder)] = 'ERROR: ' . $e->getMessage();
            }
        }

        // Link storage/app/public -> public/storage so uploaded post & photo
        // images are web-served (idempotent; ignores "already exists").
        try {
            Artisan::call('storage:link');
            $output['storage:link'] = trim(Artisan::output()) ?: 'done';
        } catch (\Throwable $e) {
            $output['storage:link'] = 'ERROR: ' . $e->getMessage();
        }

        // Re-cache config/routes for production speed (optional but recommended).
        foreach (['config:cache', 'route:cache'] as $command) {
            try {
                Artisan::call($command);
                $output[$command] = trim(Artisan::output()) ?: 'done';
            } catch (\Throwable $e) {
                $output[$command] = 'ERROR: ' . $e->getMessage();
            }
        }

        return $this->ok([
            'message' => 'Deploy hook finished. Caches cleared, migrations run, essential seeders applied.',
            'output' => $output,
        ]);
    }

    /**
     * Key-guarded self-diagnostics: the usual reasons login / the app return a
     * 500 "Server error." on shared hosting. Visit
     * /api/v1/system/diagnostics?key=DEPLOY_KEY to see what's wrong without SSH.
     */
    public function diagnostics(Request $request): JsonResponse
    {
        $expected = $this->deployKey();
        if (! $expected) {
            return $this->fail('Diagnostics disabled. Set DEPLOY_KEY in .env to enable it.', [], 403);
        }
        $key = $request->header('X-Deploy-Key') ?: (string) $request->query('key');
        if (! hash_equals($expected, $key)) {
            return $this->fail('Invalid deploy key.', [], 403);
        }

        $checks = [];

        // APP_KEY — without it sessions/encryption fail.
        $checks['app_key_set'] = (bool) config('app.key');

        // JWT secret — a blank one makes every login 500 (token can't be signed).
        $jwtSecret = config('jwt.secret');
        $checks['jwt_secret_set'] = ! empty($jwtSecret);

        // Database connectivity.
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $checks['database'] = 'connected';
        } catch (\Throwable $e) {
            $checks['database'] = 'ERROR: ' . $e->getMessage();
        }

        // Pending migrations (new tables/columns not yet applied → 500s elsewhere).
        try {
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_values(array_diff($files, $ran));
            $checks['pending_migrations'] = count($pending);
            $checks['pending_migration_names'] = array_slice($pending, 0, 10);
        } catch (\Throwable $e) {
            $checks['pending_migrations'] = 'ERROR: ' . $e->getMessage();
        }

        // Can a token actually be signed? (the exact login failure point)
        try {
            \Illuminate\Support\Facades\Auth::guard('api');
            $probe = new \App\Models\User(['email' => 'diagnostic@local']);
            $probe->id = 0;
            \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($probe);
            $checks['jwt_can_sign'] = true;
        } catch (\Throwable $e) {
            $checks['jwt_can_sign'] = 'ERROR: ' . $e->getMessage();
        }

        // Storage writable (logs/sessions/cache).
        try {
            $probe = storage_path('framework/__diag_' . uniqid());
            @file_put_contents($probe, 'ok');
            $checks['storage_writable'] = is_file($probe);
            @unlink($probe);
        } catch (\Throwable $e) {
            $checks['storage_writable'] = 'ERROR: ' . $e->getMessage();
        }

        $checks['queue_connection'] = (string) config('queue.default');
        $checks['config_cached'] = app()->configurationIsCached();
        $checks['app_debug'] = (bool) config('app.debug');

        $ok = ($checks['app_key_set'] === true)
            && ($checks['jwt_secret_set'] === true)
            && ($checks['database'] === 'connected')
            && ($checks['jwt_can_sign'] === true)
            && ($checks['storage_writable'] === true)
            && ($checks['pending_migrations'] === 0);

        return $this->ok(['healthy' => $ok, 'checks' => $checks]);
    }

    /**
     * Read DEPLOY_KEY. Falls back to parsing raw .env when config is cached
     * (env() returns null then) — which is exactly the situation this hook fixes.
     */
    private function deployKey(): ?string
    {
        $key = env('DEPLOY_KEY');
        if ($key) {
            return (string) $key;
        }

        $path = base_path('.env');
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*DEPLOY_KEY\s*=\s*(.+)$/', $line, $m)) {
                    return trim($m[1], " \"'");
                }
            }
        }

        return null;
    }
}
