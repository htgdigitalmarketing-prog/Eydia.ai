<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BulkSend;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\BulkSendService;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkSendController extends Controller
{
    public function __construct(
        private readonly BulkSendService $service,
        private readonly WhatsAppMessageService $messages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $sends = BulkSend::with('phoneNumber')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        foreach ($sends->items() as $send) {
            if ($this->service->claimIfStalled($send)) {
                $this->service->continueAfterResponse($send);
            }
        }

        return $this->ok([
            'bulk_sends' => $sends->items(),
            'meta' => [
                'current_page' => $sends->currentPage(),
                'last_page' => $sends->lastPage(),
                'total' => $sends->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $send = BulkSend::where('uuid', $uuid)->firstOrFail();

        if ($this->service->claimIfStalled($send)) {
            $this->service->continueAfterResponse($send);
        }

        return $this->ok(['bulk_send' => $send->load(['phoneNumber', 'recipients'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'numbers' => ['required', 'array', 'min:1', 'max:1000'],
            'numbers.*' => ['required', 'string', 'max:20'],
            'template' => ['required', 'string', 'max:512'],
            'language' => ['nullable', 'string', 'max:16'],
            // Ordered BODY variable values ({{1}}, {{2}}, …). Same values go to
            // every recipient (bulk send is one message to many numbers).
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            // A media HEADER is supplied either as a public URL or an uploaded
            // media id (from /bulk-send/upload-header). Either one works.
            'header_media_url' => ['nullable', 'url', 'max:2048'],
            'header_media_id' => ['nullable', 'string', 'max:128'],
        ]);

        $components = [];

        // A media HEADER (image/video/document) must be supplied at send time, or
        // Meta rejects every message — the cause of "request could not be completed".
        $headerFormat = $this->templateHeaderFormat(
            $request->user()->tenant_id, $data['template'], $data['language'] ?? null
        );
        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            if (empty($data['header_media_url']) && empty($data['header_media_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'header_media_url' => ['This template has an ' . strtolower($headerFormat)
                        . ' header. Upload an image/file or paste a public URL to send it.'],
                ]);
            }
            $mediaKey = strtolower($headerFormat); // image | video | document
            $media = ! empty($data['header_media_id'])
                ? ['id' => $data['header_media_id']]
                : ['link' => $data['header_media_url']];
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => $mediaKey,
                    $mediaKey => $media,
                ]],
            ];
        }

        // Build the Meta template "components" payload for body variables.
        $variables = $data['variables'] ?? [];
        if (! empty($variables)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($variables)
                ),
            ];
        }

        $result = $this->service->send(
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            numbers: $data['numbers'],
            template: $data['template'],
            language: $data['language'] ?? 'en_US',
            components: $components,
        );

        return $this->ok(['bulk_send' => $result], 201);
    }

    /** Upload a local header image/video/document to Meta; returns a media id. */
    public function uploadHeader(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $request->validate([
            'file' => ['required', 'file', 'max:16384', 'mimes:jpg,jpeg,png,mp4,pdf'],
        ]);

        $phone = WhatsappPhoneNumber::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('status', ['connected', 'registered'])
            ->orderByDesc('is_default')
            ->first();

        if (! $phone) {
            return $this->fail('Connect and register a WhatsApp number before uploading media.', [], 422);
        }

        $file = $request->file('file');

        try {
            $mediaId = $this->messages->uploadMediaFile($phone, $file->getRealPath(), $file->getMimeType() ?: 'application/octet-stream');
        } catch (\Throwable $e) {
            return $this->fail('Could not upload the image to WhatsApp: ' . $e->getMessage(), [], 422);
        }

        if (! $mediaId) {
            return $this->fail('WhatsApp did not return a media id. Please try a different file.', [], 422);
        }

        return $this->ok(['media_id' => $mediaId]);
    }

    /** Re-send only the failed numbers of a bulk send (no re-entry needed). */
    public function retry(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $send = BulkSend::where('uuid', $uuid)->firstOrFail();
        $result = $this->service->retryFailed($send);

        return $this->ok(['bulk_send' => $result->load(['phoneNumber', 'recipients'])]);
    }

    /** HEADER format (TEXT/IMAGE/VIDEO/DOCUMENT) of a template, or null if none. */
    private function templateHeaderFormat(int $tenantId, string $name, ?string $language): ?string
    {
        $template = \App\Models\Template::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($language, fn ($q) => $q->where('language', $language))
            ->with('components')
            ->first();

        if (! $template) {
            return null;
        }

        $header = $template->components->firstWhere('type', 'HEADER');

        return $header?->format ? strtoupper($header->format) : null;
    }
}
