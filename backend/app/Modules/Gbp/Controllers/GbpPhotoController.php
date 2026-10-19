<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpLocation;
use App\Models\GbpPhoto;
use App\Models\Integration;
use App\Modules\Gbp\Services\GoogleGbpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Google Business Profile photos (media). Stored per location; publishing pushes
 * the image to Google via the Business Profile media API (Google fetches the
 * bytes from the image URL, so it must be publicly reachable).
 */
class GbpPhotoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GbpPhoto::query()->with('location:id,uuid,name')->latest();
        if ($loc = $request->query('location')) {
            $query->whereHas('location', fn ($q) => $q->where('uuid', $loc));
        }

        return $this->ok(['photos' => $query->limit(200)->get()->map(fn (GbpPhoto $p) => $this->arr($p))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string'],
            'image_url' => ['required', 'url', 'max:1024'],
            'caption' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'in:COVER,PROFILE,LOGO,EXTERIOR,INTERIOR,PRODUCT,AT_WORK,FOOD_AND_DRINK,ADDITIONAL'],
        ]);
        $location = GbpLocation::where('uuid', $data['location'])->firstOrFail();

        $photo = GbpPhoto::create([
            'tenant_id' => $request->user()->tenant_id,
            'gbp_location_id' => $location->id,
            'image_url' => $data['image_url'],
            'caption' => $data['caption'] ?? null,
            'category' => $data['category'] ?? 'ADDITIONAL',
            'status' => 'DRAFT',
        ]);

        return $this->ok(['photo' => $this->arr($photo->load('location'))], 201);
    }

    public function publish(Request $request, string $uuid, GoogleGbpProvider $provider): JsonResponse
    {
        $photo = GbpPhoto::where('uuid', $uuid)->firstOrFail();
        $integration = $this->connected();

        if (! $integration || ! $photo->location?->google_location_id) {
            return $this->fail('Connect your Google Business Profile to publish photos to Google.', [], 422);
        }

        $name = $provider->uploadPhoto($integration, $photo->location->google_location_id, $photo->image_url, $photo->category);
        if (! $name) {
            $photo->update(['status' => 'FAILED']);

            return $this->fail($provider->getLastError() ?? 'Could not publish the photo to Google.', [], 502);
        }

        $photo->update(['status' => 'PUBLISHED', 'google_media_name' => $name, 'published_at' => now()]);

        return $this->ok(['photo' => $this->arr($photo->fresh('location'))]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        GbpPhoto::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Photo deleted.']);
    }

    private function connected(): ?Integration
    {
        $i = Integration::where('provider', Integration::GOOGLE_GBP)->first();

        return $i && $i->isConnected() ? $i : null;
    }

    private function arr(GbpPhoto $p): array
    {
        return [
            'id' => $p->uuid,
            'location' => $p->location ? ['id' => $p->location->uuid, 'name' => $p->location->name] : null,
            'image_url' => $p->image_url,
            'caption' => $p->caption,
            'category' => $p->category,
            'status' => $p->status,
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
