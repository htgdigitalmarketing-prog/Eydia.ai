<?php

namespace App\Modules\Templates\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\WhatsappBusinessAccount;
use App\Modules\Templates\Resources\TemplateResource;
use App\Modules\WhatsApp\Services\WhatsAppProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private readonly WhatsAppProviderFactory $factory) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('templates.view');

        $query = Template::with('businessAccount', 'components')->orderBy('name');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($category = $request->query('category')) {
            $query->where('category', strtoupper($category));
        }

        $templates = $query->paginate($request->integer('per_page', 50));

        return $this->ok([
            'templates' => TemplateResource::collection($templates),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('templates.view');

        $template = Template::where('uuid', $uuid)
            ->with(['businessAccount', 'components'])
            ->firstOrFail();

        return $this->ok(['template' => new TemplateResource($template)]);
    }

    /**
     * Create a WhatsApp message template and submit it to Meta for approval.
     * Builds the Graph "components" array from a friendly structured payload
     * (header / body / footer / buttons) so the frontend stays simple.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('templates.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:12'],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'waba_id' => ['nullable', 'string'],
            'header_format' => ['nullable', 'in:NONE,TEXT,IMAGE,VIDEO,DOCUMENT'],
            'header_text' => ['nullable', 'string', 'max:60'],
            // Meta resumable-upload handle for a media header (from /templates/upload-sample).
            'header_handle' => ['nullable', 'string'],
            'body' => ['required', 'string', 'max:1024'],
            'body_example' => ['nullable', 'array'],
            'body_example.*' => ['nullable', 'string'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required_with:buttons', 'in:QUICK_REPLY,URL,PHONE_NUMBER,COPY_CODE'],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'string', 'max:20'],
            'buttons.*.example' => ['nullable', 'string', 'max:60'],
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_templates', 'templates', 'templates');

        $waba = WhatsappBusinessAccount::where('tenant_id', $tenantId)
            ->when($data['waba_id'] ?? null, fn ($q, $id) => $q->where('uuid', $id)->orWhere('waba_id', $id))
            ->first();

        if (! $waba) {
            return $this->fail('Connect a WhatsApp Business account before creating templates.', [], 422);
        }

        if (Template::where('whatsapp_business_account_id', $waba->id)
            ->where('name', $data['name'])->where('language', $data['language'])->exists()) {
            return $this->fail('A template with this name and language already exists.', [], 422);
        }

        // Meta requires a sample for a media header; reject early with a clear message.
        if (in_array($data['header_format'] ?? 'NONE', ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && empty($data['header_handle'])) {
            return $this->fail('Please upload a sample ' . strtolower($data['header_format'])
                . ' for the header before submitting this template.', ['header_handle' => ['A sample file is required.']], 422);
        }

        $components = $this->buildComponents($data);

        $payload = [
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $data['category'],
            'components' => $components,
        ];

        try {
            $result = $this->factory->for($waba)->createTemplate($waba->waba_id, $payload);
        } catch (\Throwable $e) {
            return $this->fail('Meta rejected the template: ' . $e->getMessage(), [], 422);
        }

        $template = Template::create([
            'tenant_id' => $tenantId,
            'whatsapp_business_account_id' => $waba->id,
            'meta_template_id' => $result['id'] ?? null,
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $result['category'] ?? $data['category'],
            'status' => $result['status'] ?? 'PENDING',
            'raw' => array_merge($payload, ['meta' => $result]),
            'last_synced_at' => now(),
        ]);

        foreach ($components as $idx => $comp) {
            $template->components()->create([
                'tenant_id' => $tenantId,
                'type' => $comp['type'],
                'format' => $comp['format'] ?? null,
                'text' => $comp['text'] ?? null,
                'example' => $comp['example'] ?? null,
                'buttons' => $comp['buttons'] ?? null,
                'sort_order' => $idx,
            ]);
        }

        return $this->ok([
            'template' => new TemplateResource($template->load('businessAccount', 'components')),
            'message' => 'Template submitted to Meta for approval. Approval usually takes a few minutes to 24 hours.',
        ], 201);
    }

    /**
     * Upload a sample image/video/document that a media-header template needs for
     * Meta's review. Returns a header_handle to pass to store().
     */
    public function uploadSample(Request $request): JsonResponse
    {
        $this->authorize('templates.create');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,mp4,pdf'],
            'waba_id' => ['nullable', 'string'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $waba = WhatsappBusinessAccount::where('tenant_id', $tenantId)
            ->when($data['waba_id'] ?? null, fn ($q, $id) => $q->where('uuid', $id)->orWhere('waba_id', $id))
            ->first();

        if (! $waba) {
            return $this->fail('Connect a WhatsApp Business account before uploading a sample.', [], 422);
        }

        $appId = config('services.meta.app_id');
        if (! $appId) {
            return $this->fail('Meta app is not configured, so sample uploads are unavailable.', [], 422);
        }

        $file = $request->file('file');

        try {
            $handle = $this->factory->for($waba)->uploadTemplateMedia(
                (string) $appId,
                file_get_contents($file->getRealPath()),
                $file->getMimeType() ?: 'application/octet-stream',
                $file->getClientOriginalName() ?: 'sample',
            );
        } catch (\Throwable $e) {
            return $this->fail('Could not upload the sample to Meta: ' . $e->getMessage(), [], 422);
        }

        return $this->ok(['header_handle' => $handle]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('templates.delete');

        $template = Template::where('uuid', $uuid)->with('businessAccount')->firstOrFail();

        try {
            if ($template->businessAccount) {
                $this->factory->for($template->businessAccount)->deleteTemplate($template->businessAccount->waba_id, $template->name);
            }
        } catch (\Throwable $e) {
            \Log::warning('template.delete.meta_failed', ['template' => $template->name, 'error' => $e->getMessage()]);
        }

        $template->components()->delete();
        $template->delete();

        return $this->ok(['message' => 'Template deleted.']);
    }

    /** Map the structured request into Meta's Graph "components" array. */
    private function buildComponents(array $data): array
    {
        $components = [];

        $headerFormat = $data['header_format'] ?? 'NONE';
        if ($headerFormat === 'TEXT' && ! empty($data['header_text'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $data['header_text']];
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $header = ['type' => 'HEADER', 'format' => $headerFormat];
            if (! empty($data['header_handle'])) {
                $header['example'] = ['header_handle' => [$data['header_handle']]];
            }
            $components[] = $header;
        }

        $body = ['type' => 'BODY', 'text' => $data['body']];
        $examples = array_values(array_filter($data['body_example'] ?? [], fn ($v) => $v !== null && $v !== ''));
        preg_match_all('/\{\{\s*\d+\s*\}\}/', $data['body'], $vars);
        if (! empty($vars[0]) && ! empty($examples)) {
            $body['example'] = ['body_text' => [$examples]];
        }
        $components[] = $body;

        if (! empty($data['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer']];
        }

        if (! empty($data['buttons'])) {
            $buttons = [];
            foreach ($data['buttons'] as $b) {
                $btn = ['type' => $b['type'], 'text' => $b['text']];
                if ($b['type'] === 'URL' && ! empty($b['url'])) {
                    $btn['url'] = $b['url'];
                } elseif ($b['type'] === 'PHONE_NUMBER' && ! empty($b['phone_number'])) {
                    $btn['phone_number'] = $b['phone_number'];
                } elseif ($b['type'] === 'COPY_CODE') {
                    $btn['example'] = [$b['example'] ?? $b['text']];
                }
                $buttons[] = $btn;
            }
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorize('templates.sync');

        $tenantId = $request->user()->tenant_id;
        $accounts = WhatsappBusinessAccount::where('tenant_id', $tenantId)->get();

        if ($accounts->isEmpty()) {
            return $this->ok(['message' => 'No WhatsApp accounts to sync.', 'synced' => 0]);
        }

        $synced = 0;

        foreach ($accounts as $waba) {
            try {
                $provider = $this->factory->for($waba);
                $metaTemplates = $provider->getTemplates($waba->waba_id);

                foreach ($metaTemplates['data'] ?? [] as $mt) {
                    $template = Template::updateOrCreate(
                        [
                            'whatsapp_business_account_id' => $waba->id,
                            'name' => $mt['name'],
                            'language' => $mt['language'],
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'meta_template_id' => $mt['id'] ?? null,
                            'category' => $mt['category'] ?? 'UTILITY',
                            'status' => $mt['status'] ?? 'PENDING',
                            'rejection_reason' => $mt['rejected_reason'] ?? null,
                            'quality_score' => $mt['quality_score']['score'] ?? null,
                            'raw' => $mt,
                            'last_synced_at' => now(),
                        ],
                    );

                    $template->components()->delete();
                    foreach ($mt['components'] ?? [] as $idx => $comp) {
                        $template->components()->create([
                            'tenant_id' => $tenantId,
                            'type' => $comp['type'],
                            'format' => $comp['format'] ?? null,
                            'text' => $comp['text'] ?? null,
                            'example' => $comp['example'] ?? null,
                            'buttons' => $comp['buttons'] ?? null,
                            'sort_order' => $idx,
                        ]);
                    }

                    $synced++;
                }
            } catch (\Throwable $e) {
                \Log::warning('template.sync.failed', ['waba' => $waba->waba_id, 'error' => $e->getMessage()]);
            }
        }

        return $this->ok(['message' => "Synced {$synced} templates from Meta.", 'synced' => $synced]);
    }
}
