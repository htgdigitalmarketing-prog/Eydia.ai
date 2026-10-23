<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CreditLedger;
use App\Models\CreditPack;
use App\Modules\Billing\Services\CreditService;
use App\Support\Gst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI credit balance, cost table and usage ledger for the signed-in tenant,
 * plus the buyable credit packs and the Razorpay top-up flow.
 */
class CreditController extends Controller
{
    public function __construct(private readonly CreditService $credits) {}

    /** Active credit packs (for the Buy Credits page), with GST breakdown. */
    public function packs(Request $request): JsonResponse
    {
        $balance = $request->user()->tenant_id ? $this->credits->balance($request->user()->tenant_id) : 0;

        return $this->ok([
            'balance' => $balance,
            'packs' => CreditPack::where('is_active', true)->orderBy('sort_order')->orderBy('price')
                ->get()->map(fn (CreditPack $p) => $this->packArray($p))->all(),
        ]);
    }

    /** Create a Razorpay order for a credit pack. */
    public function orderPack(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('billing.manage');
        $pack = CreditPack::where('uuid', $uuid)->where('is_active', true)->firstOrFail();
        $g = Gst::inclusivePaise((float) $pack->price, (int) $pack->gst_rate);

        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (! $key || ! $secret) {
            return $this->fail('Online payments are not configured. Please contact support.', [], 503);
        }

        $response = Http::withBasicAuth($key, $secret)->acceptJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $g['total'],
                'currency' => 'INR',
                'receipt' => 'pack_' . $pack->uuid . '_' . now()->timestamp,
                'notes' => ['pack' => $pack->name, 'credits' => (string) $pack->credits, 'tenant_id' => (string) $request->user()->tenant_id],
            ]);

        if ($response->failed()) {
            $msg = data_get($response->json(), 'error.description', 'Could not create the payment order.');

            return $this->fail('Razorpay: ' . $msg, [], 502);
        }

        return $this->ok([
            'order_id' => $response->json('id'),
            'amount' => $g['total'],
            'subtotal' => $g['base'],
            'tax' => $g['tax'],
            'gst_rate' => $g['rate'],
            'currency' => 'INR',
            'key_id' => $key,
            'pack' => $this->packArray($pack),
        ]);
    }

    /** Verify a Razorpay pack payment and credit the wallet. */
    public function confirmPack(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');
        $data = $request->validate([
            'pack_id' => ['required', 'string', 'exists:credit_packs,uuid'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $secret = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], (string) $secret);
        if (! hash_equals($expected, $data['razorpay_signature'])) {
            Log::warning('credits.verify: signature mismatch', ['order_id' => $data['razorpay_order_id']]);

            return $this->fail('Payment verification failed. If money was deducted it will be refunded automatically.', [], 422);
        }

        $pack = CreditPack::where('uuid', $data['pack_id'])->firstOrFail();
        $wallet = $this->credits->add(
            $request->user()->tenant_id,
            $pack->credits,
            'purchase',
            "Bought {$pack->name} ({$pack->credits} credits)",
            $request->user()->id,
        );

        return $this->ok([
            'message' => "Payment successful — {$pack->credits} credits added.",
            'balance' => $wallet->balance,
        ]);
    }

    private function packArray(CreditPack $p): array
    {
        $g = $p->gstBreakdown();

        return [
            'id' => $p->uuid, 'name' => $p->name, 'credits' => $p->credits,
            'price' => (float) $p->price, 'gst_rate' => $p->gst_rate,
            'base' => $g['base'], 'gst' => $g['tax'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        if (! $tenantId) {
            return $this->ok(['balance' => null, 'allowance' => null, 'reset_at' => null, 'costs' => CreditService::COSTS, 'ledger' => []]);
        }

        $wallet = $this->credits->walletFor($tenantId);

        return $this->ok([
            'balance' => $wallet->balance,
            'allowance' => $wallet->monthly_allowance,
            'reset_at' => $wallet->reset_at?->toIso8601String(),
            'costs' => CreditService::COSTS,
            'ledger' => $this->credits->ledger($tenantId)->map(fn (CreditLedger $l) => [
                'id' => $l->uuid,
                'delta' => $l->delta,
                'balance_after' => $l->balance_after,
                'action' => $l->action,
                'description' => $l->description,
                'at' => $l->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
