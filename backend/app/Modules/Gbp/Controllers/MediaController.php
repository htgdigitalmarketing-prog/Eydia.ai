<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves generated/uploaded media (GBP post images, AI photos) straight from the
 * public disk through the app front controller, so the URL works regardless of
 * whether the web root is symlinked to storage. Public + cacheable; Google also
 * fetches post images from here.
 */
class MediaController extends Controller
{
    public function show(string $path): Response
    {
        // No path traversal, and only our media folders.
        if (str_contains($path, '..') || ! preg_match('#^(gbp|ai|uploads)/#', $path)) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            abort(404);
        }

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        return response($disk->get($path), 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
