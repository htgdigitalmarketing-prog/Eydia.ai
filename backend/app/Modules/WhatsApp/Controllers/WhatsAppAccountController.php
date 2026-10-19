<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Requests\ConnectManualRequest;
use App\Modules\WhatsApp\Requests\EmbeddedSignupRequest;
use App\Modules\WhatsApp\Resources\WhatsappAccountResource;
use App\Modules\WhatsApp\Resources\WhatsappNumberResource;
use App\Modules\WhatsApp\Services\WhatsAppAccountService;
use App\Modules\WhatsApp\Services\WhatsAppEmbeddedSignupService;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        private readonly WhatsAppAccountService $accounts,
        private readonly WhatsAppEmbeddedSignupService $embedded,
        private readonly WhatsAppMessageService $messages,
    ) {}

    /** Frontend needs the public app id + config id to launch Embedded Signup. */
    public function config(): JsonResponse
    {
        return $this->ok([
            'app_id' => config('services.meta.app_id'),
            'config_id' => config('services.meta.config_id'),
            'api_version' => config('services.meta.api_version', 'v23.0'),
            'embedded_signup_configured' => $this->embedded->isConfigured(),
        ]);
    }

    public function numbers(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        return $this->ok([
            'numbers' => WhatsappNumberResource::collection(
                $this->accounts->numbersFor($this->tenantId($request))
            ),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        return $this->ok([
            'accounts' => WhatsappAccountResource::collection(
                $this->accounts->accountsFor($this->tenantId($request))
            ),
        ]);
    }

    public function connectManual(ConnectManualRequest $request): JsonResponse
    {
        $this->authorize('whatsapp.connect');

        $phone = $this->accounts->connectManual($this->tenantId($request), $request->validated());

        return $this->ok(['number' => new WhatsappNumberResource($phone)], 201);
    }

    public function embeddedSignup(EmbeddedSignupRequest $request): JsonResponse
    {
        $this->authorize('whatsapp.connect');

        $waba = $this->embedded->connect(
            $this->tenantId($request),
            $request->string('code'),
            $request->string('waba_id'),
            $request->input('phone_number_id'),
        );

        return $this->ok(['account' => new WhatsappAccountResource($waba->load('phoneNumbers'))], 201);
    }

    public function sync(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        return $this->ok(['number' => new WhatsappNumberResource($this->accounts->syncNumber($number))]);
    }

    public function register(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');
        $request->validate(['pin' => ['required', 'digits:6']]);

        return $this->ok(['number' => new WhatsappNumberResource(
            $this->accounts->registerNumber($number, $request->string('pin'))
        )]);
    }

    /** Subscribe the app to this number's WABA (fixes Meta #200 "no permission to send"). */
    public function subscribeApp(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');
        $this->accounts->subscribeApp($number);

        return $this->ok(['message' => 'App subscribed to the WhatsApp Business Account. Try sending again.']);
    }

    /** Update the WABA access token for a connected number (fixes #200 token issues). */
    public function updateToken(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');
        $request->validate(['access_token' => ['required', 'string', 'min:10']]);

        $waba = $number->businessAccount()->firstOrFail();
        $waba->update(['access_token' => $request->string('access_token')]);

        // Re-subscribe app with the new token.
        try {
            $this->accounts->subscribeApp($number);
        } catch (\Throwable $e) {
            \Log::warning('whatsapp.subscribe_app.failed on token update: ' . $e->getMessage());
        }

        return $this->ok(['message' => 'Access token updated. Try sending again.']);
    }

    public function destroy(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');
        $this->accounts->disconnect($number);

        return $this->ok(['message' => 'WhatsApp number disconnected.']);
    }

    /**
     * Send a real message from this number via the Cloud API.
     * Default is the pre-approved `hello_world` template, which is the only
     * reliable way to *initiate* a chat (free-form text needs an open 24h window).
     */
    public function sendTest(Request $request, WhatsappPhoneNumber $number): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'to' => ['required', 'string', 'max:20'],
            'type' => ['nullable', 'in:template,text'],
            'body' => ['nullable', 'string', 'max:1000'],
            'template' => ['nullable', 'string', 'max:512'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        // Meta wants the recipient in E.164 without the leading '+'.
        $to = preg_replace('/\D/', '', $data['to']);

        if (($data['type'] ?? 'template') === 'text') {
            $result = $this->messages->sendText($number, $to, $data['body'] ?? 'Hello from Pizi 👋');
        } else {
            $result = $this->messages->sendTemplate(
                $number,
                $to,
                $data['template'] ?? 'hello_world',
                $data['language'] ?? 'en_US',
            );
        }

        return $this->ok([
            'wamid' => $this->messages->wamid($result),
            'to' => $to,
        ]);
    }

    /** Tenant-scoped actions require an actual tenant (super admin must switch). */
    private function tenantId(Request $request): int
    {
        $id = $request->user()->tenant_id;

        abort_if($id === null, 422, 'Switch to a company account to manage WhatsApp numbers.');

        return $id;
    }
}
