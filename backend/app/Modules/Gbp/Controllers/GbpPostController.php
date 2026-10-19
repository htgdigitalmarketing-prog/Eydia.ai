<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpLocation;
use App\Models\GbpPost;
use App\Models\Integration;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Google Business Profile posts (localPosts). Drafts are stored per location;
 * publishing pushes to Google via the Business Profile API when connected.
 */
class GbpPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GbpPost::query()->with('location:id,uuid,name')->latest();
        if ($loc = $request->query('location')) {
            $query->whereHas('location', fn ($q) => $q->where('uuid', $loc));
        }

        return $this->ok(['posts' => $query->limit(200)->get()->map(fn (GbpPost $p) => $this->arr($p))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string'],
            'summary' => ['required', 'string', 'max:1500'],
            'type' => ['nullable', 'in:STANDARD,EVENT,OFFER,ALERT'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'cta_type' => ['nullable', 'in:NONE,LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP'],
            'cta_url' => ['nullable', 'url', 'max:1024'],
        ]);
        $location = GbpLocation::where('uuid', $data['location'])->firstOrFail();

        $post = GbpPost::create([
            'tenant_id' => $request->user()->tenant_id,
            'gbp_location_id' => $location->id,
            'type' => $data['type'] ?? 'STANDARD',
            'summary' => $data['summary'],
            'image_url' => $data['image_url'] ?? null,
            'cta_type' => ($data['cta_type'] ?? 'NONE') === 'NONE' ? null : $data['cta_type'],
            'cta_url' => $data['cta_url'] ?? null,
            'status' => 'DRAFT',
        ]);

        return $this->ok(['post' => $this->arr($post->load('location'))], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'summary' => ['sometimes', 'string', 'max:1500'],
            'type' => ['sometimes', 'in:STANDARD,EVENT,OFFER,ALERT'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'cta_type' => ['nullable', 'in:NONE,LEARN_MORE,CALL,ORDER,BOOK,SIGN_UP,SHOP'],
            'cta_url' => ['nullable', 'url', 'max:1024'],
        ]);
        if (array_key_exists('cta_type', $data)) {
            $data['cta_type'] = $data['cta_type'] === 'NONE' ? null : $data['cta_type'];
        }
        $post->update($data);

        return $this->ok(['post' => $this->arr($post->fresh('location'))]);
    }

    public function publish(Request $request, string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        $integration = $this->connected();

        if (! $integration || ! $post->location?->google_location_id) {
            return $this->fail('Connect your Google Business Profile to publish posts to Google.', [], 422);
        }

        $name = $provider->createPost($integration, $post->location->google_location_id, [
            'summary' => $post->summary,
            'type' => $post->type,
            'image_url' => $post->image_url,
            'cta_type' => $post->cta_type,
            'cta_url' => $post->cta_url,
        ]);

        if (! $name) {
            $post->update(['status' => 'FAILED']);

            return $this->fail($provider->getLastError() ?? 'Could not publish the post to Google.', [], 502);
        }

        $post->update(['status' => 'PUBLISHED', 'google_post_name' => $name, 'published_at' => now()]);

        return $this->ok(['post' => $this->arr($post->fresh('location'))]);
    }

    public function destroy(string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $post = GbpPost::where('uuid', $uuid)->firstOrFail();
        if ($post->google_post_name && ($i = $this->connected())) {
            $provider->deletePost($i, $post->google_post_name);
        }
        $post->delete();

        return $this->ok(['message' => 'Post deleted.']);
    }

    private function connected(): ?Integration
    {
        $i = Integration::where('provider', Integration::GOOGLE_GBP)->first();

        return $i && $i->isConnected() ? $i : null;
    }

    private function arr(GbpPost $p): array
    {
        return [
            'id' => $p->uuid,
            'location' => $p->location ? ['id' => $p->location->uuid, 'name' => $p->location->name] : null,
            'type' => $p->type,
            'summary' => $p->summary,
            'image_url' => $p->image_url,
            'cta_type' => $p->cta_type,
            'cta_url' => $p->cta_url,
            'status' => $p->status,
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
