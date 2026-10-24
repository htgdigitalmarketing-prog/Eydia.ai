<?php

namespace App\Modules\Chatbot\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Chatbot;
use App\Models\ChatbotRule;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Resources\ChatbotResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $query = Chatbot::with('phoneNumber')
            ->withCount('rules')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if (($active = $request->query('is_active')) !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $chatbots = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'chatbots' => ChatbotResource::collection($chatbots),
            'meta' => [
                'current_page' => $chatbots->currentPage(),
                'last_page' => $chatbots->lastPage(),
                'total' => $chatbots->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $chatbot = Chatbot::where('uuid', $uuid)
            ->with(['phoneNumber', 'rules' => fn ($q) => $q->orderBy('priority')])
            ->withCount('rules')
            ->firstOrFail();

        return $this->ok(['chatbot' => new ChatbotResource($chatbot)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number_id' => ['nullable', 'string', 'exists:whatsapp_phone_numbers,uuid'],
            'welcome_message' => ['nullable', 'string'],
            'fallback_message' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_instructions' => ['nullable', 'string', 'max:8000'],
            'followup_enabled' => ['sometimes', 'boolean'],
            'followup_message' => ['nullable', 'string', 'max:1000'],
        ]);

        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($request->user()->tenant_id, 'max_chatbots', 'chatbots', 'chatbots');

        $chatbot = Chatbot::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'whatsapp_phone_number_id' => $this->resolvePhoneId($data['phone_number_id'] ?? null),
            'welcome_message' => $data['welcome_message'] ?? null,
            'fallback_message' => $data['fallback_message'] ?? null,
            'is_active' => $data['is_active'] ?? false,
            'ai_enabled' => $data['ai_enabled'] ?? false,
            'ai_instructions' => $data['ai_instructions'] ?? null,
            'followup_enabled' => $data['followup_enabled'] ?? false,
            'followup_message' => $data['followup_message'] ?? null,
        ]);

        $chatbot->load('phoneNumber')->loadCount('rules');

        return $this->ok(['chatbot' => new ChatbotResource($chatbot)], 201);
    }

    /**
     * One-click install of the ready-made "GBP Lead Automation" WhatsApp funnel
     * (welcome → free GBP check → book demo / send details, with CRM tagging).
     * Idempotent: re-running refreshes the same flow.
     */
    /** Whether the signed-in user's email is allowed the GBP lead automation. */
    private function leadFlowAllowed(Request $request): bool
    {
        $email = strtolower((string) $request->user()?->email);

        return $email !== '' && in_array($email, (array) config('features.lead_flow_emails', []), true);
    }

    public function installGbpFlow(Request $request, \App\Modules\Chatbot\Services\GbpLeadFlowInstaller $installer): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        if (! $this->leadFlowAllowed($request)) {
            return $this->fail('This automation is not enabled for your account.', [], 403);
        }

        $bot = $installer->install($request->user()->tenant_id);
        $bot->load(['phoneNumber', 'rules' => fn ($q) => $q->orderBy('priority')])->loadCount('rules');

        return $this->ok([
            'chatbot' => new ChatbotResource($bot),
            'steps' => \App\Modules\Chatbot\Services\GbpLeadFlowInstaller::steps(),
            'message' => 'GBP Lead Automation flow installed. It is now active on your WhatsApp number(s).',
        ]);
    }

    /** Whether the GBP lead-automation flow is installed, plus its step summary. */
    public function leadFlowStatus(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        // Only the allowed email(s) can see this automation; everyone else gets
        // a normal chatbot page (available=false hides the card entirely).
        if (! $this->leadFlowAllowed($request)) {
            return $this->ok(['available' => false, 'installed' => false, 'active' => false, 'id' => null, 'rules' => 0, 'steps' => []]);
        }

        $bot = Chatbot::where('is_lead_flow', true)->withCount('rules')->first();

        return $this->ok([
            'available' => true,
            'installed' => (bool) $bot,
            'active' => (bool) $bot?->is_active,
            'id' => $bot?->uuid,
            'rules' => $bot?->rules_count ?? 0,
            'steps' => \App\Modules\Chatbot\Services\GbpLeadFlowInstaller::steps(),
            'playbook' => \App\Modules\Chatbot\Services\GbpLeadFlowInstaller::playbook(),
        ]);
    }

    public function update(string $uuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number_id' => ['nullable', 'string', 'exists:whatsapp_phone_numbers,uuid'],
            'welcome_message' => ['nullable', 'string'],
            'fallback_message' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_instructions' => ['nullable', 'string', 'max:8000'],
            'followup_enabled' => ['sometimes', 'boolean'],
            'followup_message' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('phone_number_id', $data)) {
            $data['whatsapp_phone_number_id'] = $this->resolvePhoneId($data['phone_number_id']);
            unset($data['phone_number_id']);
        }

        $chatbot->update($data);
        $chatbot->load('phoneNumber')->loadCount('rules');

        return $this->ok(['chatbot' => new ChatbotResource($chatbot)]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();
        $chatbot->rules()->delete();
        $chatbot->delete();

        return $this->ok(['message' => 'Chatbot deleted.']);
    }

    public function toggle(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();
        $chatbot->update(['is_active' => ! $chatbot->is_active]);
        $chatbot->load('phoneNumber')->loadCount('rules');

        return $this->ok(['chatbot' => new ChatbotResource($chatbot)]);
    }

    public function addRule(string $uuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();
        $data = $this->validateRule($request);

        $rule = $chatbot->rules()->create(array_merge($data, [
            'tenant_id' => $request->user()->tenant_id,
        ]));

        return $this->ok(['rule' => $this->ruleArray($rule)], 201);
    }

    public function updateRule(string $uuid, string $ruleUuid, Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();
        $rule = $chatbot->rules()->where('uuid', $ruleUuid)->firstOrFail();
        $rule->update($this->validateRule($request, true));

        return $this->ok(['rule' => $this->ruleArray($rule->fresh())]);
    }

    public function deleteRule(string $uuid, string $ruleUuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $chatbot = Chatbot::where('uuid', $uuid)->firstOrFail();
        $chatbot->rules()->where('uuid', $ruleUuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Rule deleted.']);
    }

    private function validateRule(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'keyword' => [$req, 'string', 'max:255'],
            'match_type' => ['sometimes', 'string', 'in:exact,contains,starts_with,regex'],
            'response_text' => ['nullable', 'string'],
            'response_type' => ['sometimes', 'string', 'in:text,template'],
            'template_name' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ruleArray(ChatbotRule $rule): array
    {
        return [
            'id' => $rule->uuid,
            'keyword' => $rule->keyword,
            'match_type' => $rule->match_type,
            'response_text' => $rule->response_text,
            'response_type' => $rule->response_type,
            'template_name' => $rule->template_name,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
        ];
    }

    private function resolvePhoneId(?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        return WhatsappPhoneNumber::where('uuid', $uuid)->value('id');
    }
}
