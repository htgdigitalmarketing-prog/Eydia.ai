<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BusinessSite;
use App\Modules\Gbp\Services\BusinessSiteRenderer;
use Illuminate\Http\Response;

/**
 * Serves a generated business microsite at /site/{slug} to the public — no auth,
 * no tenant scope. Renders from the frozen snapshot stored on the BusinessSite.
 */
class PublicSiteController extends Controller
{
    public function show(string $slug, BusinessSiteRenderer $renderer): Response
    {
        $site = BusinessSite::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (! $site) {
            return response(
                '<!doctype html><meta charset="utf-8"><title>Not found</title>'
                . '<body style="font-family:system-ui;text-align:center;padding:80px 20px;color:#334">'
                . '<h1 style="font-size:48px;margin:0">404</h1><p>This site is not published.</p>'
                . '<p><a href="https://eydia.in" style="color:#4c57d8">Eydia.ai</a></p></body>',
                404
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response($renderer->render($site->data ?? []), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
