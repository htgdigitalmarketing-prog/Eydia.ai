<?php

namespace App\Modules\Social\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Modules\Social\Services\MetaSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialController extends Controller
{
    public function __construct(private readonly MetaSocialService $meta) {}

    // ---- connection ----

    public function connection(Request $request): JsonResponse
    {
        $this->authorize('campaigns.view');

        $conn = SocialConnection::first();

        return $this->ok([
            'connection' => $conn ? $this->connectionArray($conn) : null,
            // For the "Login with Facebook" page picker on the app.
            'facebook_app_id' => config('services.meta.app_id'),
            'api_version' => config('services.meta.api_version', 'v23.0'),
        ]);
    }

    /**
     * After "Login with Facebook" on the app, exchange the user token for a
     * long-lived one and return the Pages the user can publish to, so they can
     * pick which Page (and its linked Instagram) to connect.
     */
    public function pages(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'user_token' => ['required', 'string'],
        ]);

        try {
            $longLived = $this->meta->exchangeUserToken($data['user_token']);
            $pages = $this->meta->listPages($longLived);
        } catch (\Throwable $e) {
            return $this->fail('Could not load your Facebook Pages: ' . $e->getMessage(), [], 422);
        }

        return $this->ok(['pages' => $pages]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage'); // account connection is admin-only

        $data = $request->validate([
            'page_id' => ['required', 'string', 'max:64'],
            'page_access_token' => ['required', 'string'],
        ]);

        // Validate the token against the Graph API and pull page + IG info.
        try {
            $info = $this->meta->inspectPage($data['page_id'], $data['page_access_token']);
        } catch (\Throwable $e) {
            return $this->fail('Could not verify the Facebook Page: ' . $e->getMessage(), [], 422);
        }

        $attributes = [
            'page_id' => $data['page_id'],
            'page_name' => $info['name'],
            'page_access_token' => $data['page_access_token'],
            'ig_user_id' => $info['ig_user_id'],
            'ig_username' => $info['ig_username'],
            'status' => 'connected',
        ];

        try {
            $conn = SocialConnection::first();
            if ($conn) {
                $conn->update($attributes);
            } else {
                $conn = SocialConnection::create(array_merge($attributes, ['tenant_id' => $request->user()->tenant_id]));
            }
        } catch (\Throwable $e) {
            // Surface the real cause (e.g. a missing table/column on the DB)
            // instead of a blank "Server error."
            return $this->fail('Could not save the connection: ' . class_basename($e) . ' — ' . $e->getMessage(), [], 422);
        }

        return $this->ok(['connection' => $this->connectionArray($conn->fresh())]);
    }

    /** Re-detect the Instagram Business account for the connected Page (no reconnect). */
    public function recheckInstagram(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $conn = SocialConnection::first();
        if (! $conn) {
            return $this->fail('Connect a Facebook Page first.', [], 422);
        }

        try {
            $info = $this->meta->inspectPage($conn->page_id, $conn->page_access_token);
        } catch (\Throwable $e) {
            return $this->fail('Could not re-check the Page: ' . $e->getMessage(), [], 422);
        }

        $conn->update([
            'ig_user_id' => $info['ig_user_id'],
            'ig_username' => $info['ig_username'],
        ]);

        return $this->ok([
            'connection' => $this->connectionArray($conn->fresh()),
            'message' => $info['ig_user_id']
                ? 'Instagram linked: @' . $info['ig_username']
                : 'No Instagram Business account is linked to this Facebook Page. In Meta, link an Instagram Business/Creator account to the Page (and ensure the token has instagram permissions), then re-check.',
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        SocialConnection::query()->delete();

        return $this->ok(['message' => 'Disconnected.']);
    }

    // ---- posts ----

    public function posts(Request $request): JsonResponse
    {
        $this->authorize('campaigns.view');

        $posts = SocialPost::orderByDesc('id')->limit(50)->get();

        return $this->ok(['posts' => $posts->map(fn (SocialPost $p) => $this->postArray($p))]);
    }

    public function createPost(Request $request): JsonResponse
    {
        $this->authorize('campaigns.create');

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:2200'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime', 'max:204800'], // 200 MB
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'in:facebook,instagram'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $conn = SocialConnection::first();
        if (! $conn) {
            return $this->fail('Connect a Facebook Page first.', [], 422);
        }

        // Resolve a public media URL (uploaded file or pasted URL) and its type.
        $mediaUrl = $data['image_url'] ?? null;
        $mediaPath = null;
        $mediaType = 'image';

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mediaType = str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'image';
            $mediaPath = $file->store('social/' . $request->user()->tenant_id, 'public');
            $mediaUrl = rtrim((string) config('app.url'), '/') . '/storage/' . $mediaPath;
        } elseif ($mediaUrl) {
            $mediaType = preg_match('/\.(mp4|mov|m4v|webm)(\?.*)?$/i', $mediaUrl) ? 'video' : 'image';
        }

        if (! $mediaUrl) {
            return $this->fail('A photo or video is required — upload one or paste a public URL.', [], 422);
        }

        $post = SocialPost::create([
            'tenant_id' => $request->user()->tenant_id,
            'caption' => $data['caption'] ?? null,
            'image_url' => $mediaUrl,
            'media_type' => $mediaType,
            'image_path' => $mediaPath,
            'targets' => array_values(array_unique($data['targets'])),
            'status' => isset($data['scheduled_at']) ? 'scheduled' : 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Publish immediately unless scheduled for later.
        if (! isset($data['scheduled_at'])) {
            $this->publishPost($post, $conn);
        }

        return $this->ok(['post' => $this->postArray($post->fresh())], 201);
    }

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('campaigns.create');

        $post = SocialPost::where('uuid', $uuid)->firstOrFail();
        $conn = SocialConnection::first();
        if (! $conn) {
            return $this->fail('Connect a Facebook Page first.', [], 422);
        }

        $this->publishPost($post, $conn);

        return $this->ok(['post' => $this->postArray($post->fresh())]);
    }

    /** Edit the caption. Updates PiziDesk, and the live Facebook post if published. */
    public function updatePost(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('campaigns.create');

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:2200'],
        ]);

        $post = SocialPost::where('uuid', $uuid)->firstOrFail();
        $conn = SocialConnection::first();

        $fbId = $post->results['facebook']['id'] ?? null;
        $igEdited = false;

        if ($conn && $fbId) {
            try {
                $this->meta->editFacebookPost($conn->page_access_token, $fbId, (string) ($data['caption'] ?? ''));
            } catch (\Throwable $e) {
                return $this->fail('Could not update the Facebook post: ' . $e->getMessage(), [], 422);
            }
        }
        // Note: Instagram does not allow editing a published post's caption via API.
        $igPublished = ($post->results['instagram']['status'] ?? null) === 'published';

        $post->update(['caption' => $data['caption'] ?? null]);

        return $this->ok([
            'post' => $this->postArray($post->fresh()),
            'note' => $igPublished ? 'Caption updated. (Instagram does not allow editing a published post — only Facebook was updated.)' : null,
        ]);
    }

    /** Delete a post from PiziDesk, and from Facebook if it was published there. */
    public function deletePost(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('campaigns.create');

        $post = SocialPost::where('uuid', $uuid)->firstOrFail();
        $conn = SocialConnection::first();

        $fbId = $post->results['facebook']['id'] ?? null;
        if ($conn && $fbId) {
            try {
                $this->meta->deleteFacebookPost($conn->page_access_token, $fbId);
            } catch (\Throwable $e) {
                // best-effort — still remove from PiziDesk even if FB delete fails
            }
        }

        $igPublished = ($post->results['instagram']['status'] ?? null) === 'published';
        $post->delete();

        return $this->ok([
            'message' => 'Post deleted.',
            'note' => $igPublished ? 'Removed from PiziDesk and Facebook. Instagram posts cannot be deleted via API — remove it from the Instagram app if needed.' : null,
        ]);
    }

    // ---- publishing core ----

    private function publishPost(SocialPost $post, SocialConnection $conn): void
    {
        $results = [];
        $ok = 0;
        $fail = 0;

        $isVideo = $post->media_type === 'video';

        if (in_array('facebook', $post->targets, true)) {
            try {
                $id = $isVideo
                    ? $this->meta->publishFacebookVideo($conn->page_id, $conn->page_access_token, $post->image_url, $post->caption)
                    : $this->meta->publishFacebook($conn->page_id, $conn->page_access_token, $post->image_url, $post->caption);
                $results['facebook'] = ['status' => 'published', 'id' => $id];
                $ok++;
            } catch (\Throwable $e) {
                $results['facebook'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)];
                $fail++;
            }
        }

        if (in_array('instagram', $post->targets, true)) {
            if (! $conn->ig_user_id) {
                $results['instagram'] = ['status' => 'failed', 'error' => 'No Instagram Business account is linked to this Facebook Page.'];
                $fail++;
            } else {
                try {
                    $id = $isVideo
                        ? $this->meta->publishInstagramVideo($conn->ig_user_id, $conn->page_access_token, $post->image_url, $post->caption)
                        : $this->meta->publishInstagram($conn->ig_user_id, $conn->page_access_token, $post->image_url, $post->caption);
                    $results['instagram'] = ['status' => 'published', 'id' => $id];
                    $ok++;
                } catch (\Throwable $e) {
                    $results['instagram'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)];
                    $fail++;
                }
            }
        }

        $post->update([
            'status' => $fail === 0 ? 'published' : ($ok > 0 ? 'partial' : 'failed'),
            'results' => $results,
            'published_at' => $ok > 0 ? now() : $post->published_at,
        ]);
    }

    // ---- serialization ----

    private function connectionArray(SocialConnection $c): array
    {
        return [
            'id' => $c->uuid,
            'page_id' => $c->page_id,
            'page_name' => $c->page_name,
            'ig_user_id' => $c->ig_user_id,
            'ig_username' => $c->ig_username,
            'instagram_linked' => (bool) $c->ig_user_id,
            'status' => $c->status,
            'connected_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function postArray(SocialPost $p): array
    {
        return [
            'id' => $p->uuid,
            'caption' => $p->caption,
            'image_url' => $p->image_url,
            'media_type' => $p->media_type,
            'targets' => $p->targets,
            'status' => $p->status,
            'results' => $p->results,
            'scheduled_at' => $p->scheduled_at?->toIso8601String(),
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
