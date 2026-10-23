<?php

namespace App\Modules\AiMedia\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiMedia;
use App\Modules\Billing\Services\CreditService;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI Media generation (ReviewFlow §22): text-to-image via Gemini's image model.
 * Results are stored per tenant as base64 data URIs. Credit-gated
 * ('ai_media_generate'); when no Gemini key is set we store a labelled SVG
 * placeholder instead of charging credits, so the feature still demonstrates.
 */
class AiMediaController extends Controller
{
    public function __construct(
        private readonly AiReplyService $ai,
        private readonly CreditService $credits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $media = AiMedia::query()->latest()->limit(100)->get();

        return $this->ok(['media' => $media->map(fn (AiMedia $m) => $this->arr($m))->all()]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $tenantId = $request->user()->tenant_id;
        if ($tenantId && ! $this->credits->canAfford($tenantId, 'ai_media_generate')) {
            return $this->fail(
                'You are out of AI credits (' . $this->credits->cost('ai_media_generate') . ' needed). Top up or upgrade your plan to continue.',
                ['credits' => ['insufficient']],
                402,
            );
        }

        // Optional reference image — the AI "sees" it and builds on it.
        $refBytes = $refMime = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $refBytes = file_get_contents($file->getRealPath());
            $refMime = $file->getMimeType();
        }

        $image = $this->ai->generateImage($data['prompt'], $refBytes, $refMime);
        $source = $image ? 'ai' : 'fallback';
        if (! $image) {
            $image = $this->placeholder($data['prompt']);
        }

        $media = AiMedia::create([
            'created_by' => $request->user()->id,
            'type' => 'image',
            'prompt' => $data['prompt'],
            'image_data' => $image,
            'model' => $source === 'ai' ? config('services.gemini.image_model') : null,
            'source' => $source,
        ]);

        // Only charge when the model actually produced an image.
        if ($source === 'ai' && $tenantId) {
            $this->credits->deduct($tenantId, $request->user()->id, 'ai_media_generate', 'AI image generation');
        }

        return $this->ok([
            'media' => $this->arr($media),
            'source' => $source,
            'credits' => $tenantId ? $this->credits->balance($tenantId) : null,
        ], 201);
    }

    public function destroy(string $uuid): JsonResponse
    {
        AiMedia::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Media deleted.']);
    }

    private function arr(AiMedia $m): array
    {
        return [
            'id' => $m->uuid,
            'type' => $m->type,
            'prompt' => $m->prompt,
            'image_data' => $m->image_data,
            'model' => $m->model,
            'source' => $m->source,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** A labelled SVG placeholder (base64 data URI) when image AI is unavailable. */
    private function placeholder(string $prompt): string
    {
        $hue = crc32($prompt) % 360;
        $label = htmlspecialchars(mb_strimwidth($prompt, 0, 60, '…'), ENT_QUOTES);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640">
  <rect width="640" height="640" fill="hsl({$hue},55%,55%)"/>
  <text x="50%" y="46%" fill="white" font-family="sans-serif" font-size="26" text-anchor="middle">AI image placeholder</text>
  <text x="50%" y="54%" fill="rgba(255,255,255,0.85)" font-family="sans-serif" font-size="18" text-anchor="middle">{$label}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
