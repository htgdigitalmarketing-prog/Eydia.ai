<?php

namespace App\Console\Commands;

use App\Models\GbpPhoto;
use App\Models\GbpPost;
use App\Models\Integration;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Console\Command;

/**
 * Publishes scheduled Google Business Profile posts & photos whose time has
 * arrived. Runs across all tenants (console context), so global tenant scopes
 * are bypassed and each row's own tenant Google connection is loaded explicitly.
 */
class PublishDueGbpContent extends Command
{
    protected $signature = 'gbp:publish-due';
    protected $description = 'Publish scheduled Google Business Profile posts & photos that are now due';

    public function handle(GoogleGbpProvider $provider): int
    {
        $this->publishPosts($provider);
        $this->publishPhotos($provider);

        return self::SUCCESS;
    }

    private function connection(int $tenantId): ?Integration
    {
        $i = Integration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider', Integration::GOOGLE_GBP)
            ->first();

        return $i && $i->isConnected() ? $i : null;
    }

    private function publishPosts(GoogleGbpProvider $provider): void
    {
        $due = GbpPost::withoutGlobalScopes()
            ->where('status', 'SCHEDULED')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with('location')
            ->limit(50)->get();

        foreach ($due as $post) {
            $integration = $this->connection($post->tenant_id);
            if (! $integration || ! $post->location?->google_location_id) {
                continue; // stays SCHEDULED until the tenant connects / adds a location
            }

            $meta = $post->meta ?? [];
            $name = $provider->createPost($integration, $post->location->google_location_id, [
                'summary' => $post->summary, 'type' => $post->type, 'image_url' => $post->image_url,
                'cta_type' => $post->cta_type, 'cta_url' => $post->cta_url,
                'title' => $meta['title'] ?? null, 'coupon_code' => $meta['coupon_code'] ?? null,
                'terms' => $meta['terms'] ?? null, 'event_start' => $meta['event_start'] ?? null,
                'event_end' => $meta['event_end'] ?? null,
            ]);

            $post->update($name
                ? ['status' => 'PUBLISHED', 'google_post_name' => $name, 'published_at' => now()]
                : ['status' => 'FAILED']);
            $this->info("Post {$post->uuid}: {$post->status}");
        }
    }

    private function publishPhotos(GoogleGbpProvider $provider): void
    {
        $due = GbpPhoto::withoutGlobalScopes()
            ->where('status', 'SCHEDULED')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with('location')
            ->limit(50)->get();

        foreach ($due as $photo) {
            $integration = $this->connection($photo->tenant_id);
            if (! $integration || ! $photo->location?->google_location_id) {
                continue;
            }

            $name = $provider->uploadPhoto($integration, $photo->location->google_location_id, $photo->image_url, $photo->category);
            $photo->update($name
                ? ['status' => 'PUBLISHED', 'google_media_name' => $name, 'published_at' => now()]
                : ['status' => 'FAILED']);
            $this->info("Photo {$photo->uuid}: {$photo->status}");
        }
    }
}
