<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Modules\Social\Services\MetaSocialService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Publishes scheduled social posts whose time has arrived. Runs across all
 * tenants (console context), so global tenant scopes are bypassed and each
 * post's own tenant connection is loaded explicitly.
 */
class PublishDueSocialPosts extends Command
{
    protected $signature = 'social:publish-due';
    protected $description = 'Publish scheduled Facebook/Instagram posts that are now due';

    public function handle(MetaSocialService $meta): int
    {
        $due = SocialPost::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->limit(50)
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($due as $post) {
            $conn = SocialConnection::withoutGlobalScopes()
                ->where('tenant_id', $post->tenant_id)
                ->first();

            if (! $conn) {
                $post->update(['status' => 'failed', 'results' => ['error' => 'No social connection.']]);
                continue;
            }

            $results = [];
            $ok = 0; $fail = 0;
            $isVideo = $post->media_type === 'video';

            if (in_array('facebook', $post->targets, true)) {
                try {
                    $id = $isVideo
                        ? $meta->publishFacebookVideo($conn->page_id, $conn->page_access_token, $post->image_url, $post->caption)
                        : $meta->publishFacebook($conn->page_id, $conn->page_access_token, $post->image_url, $post->caption);
                    $results['facebook'] = ['status' => 'published', 'id' => $id]; $ok++;
                } catch (\Throwable $e) {
                    $results['facebook'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)]; $fail++;
                }
            }

            if (in_array('instagram', $post->targets, true)) {
                if (! $conn->ig_user_id) {
                    $results['instagram'] = ['status' => 'failed', 'error' => 'No Instagram Business account linked.']; $fail++;
                } else {
                    try {
                        $id = $isVideo
                            ? $meta->publishInstagramVideo($conn->ig_user_id, $conn->page_access_token, $post->image_url, $post->caption)
                            : $meta->publishInstagram($conn->ig_user_id, $conn->page_access_token, $post->image_url, $post->caption);
                        $results['instagram'] = ['status' => 'published', 'id' => $id]; $ok++;
                    } catch (\Throwable $e) {
                        $results['instagram'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)]; $fail++;
                    }
                }
            }

            $post->update([
                'status' => $fail === 0 ? 'published' : ($ok > 0 ? 'partial' : 'failed'),
                'results' => $results,
                'published_at' => $ok > 0 ? now() : null,
            ]);

            $this->info("Post {$post->uuid}: {$post->status}");
        }

        return self::SUCCESS;
    }
}
