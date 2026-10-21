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
        // plans, roles/permissions and the platform super admin. This makes a
        // fresh no-SSH deploy fully self-serve. Demo data is intentionally NOT
        // seeded here.
        foreach ([
            \Database\Seeders\RolePermissionSeeder::class,
            \Database\Seeders\PlanSeeder::class,
            \Database\Seeders\SuperAdminSeeder::class,
        ] as $seeder) {
            try {
                Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                $output['seed:' . class_basename($seeder)] = trim(Artisan::output()) ?: 'done';
            } catch (\Throwable $e) {
                $output['seed:' . class_basename($seeder)] = 'ERROR: ' . $e->getMessage();
            }
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
