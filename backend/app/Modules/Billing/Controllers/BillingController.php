<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Read-side billing for a tenant: current plan/subscription, wallet balance and
 * ledger, available plans, and invoice history. Plan changes are recorded here;
 * real payment capture is delegated to a gateway (Razorpay/Stripe) as a
 * follow-up — this module never moves real money on its own.
 */
class BillingController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $tenant = $request->user()->tenant;
        $subscription = Subscription::with('plan')->latest('id')->first();
        $wallet = $this->walletFor($request);

        return $this->ok([
            'subscription' => $subscription ? $this->subscriptionArray($subscription) : null,
            'wallet' => $this->walletArray($wallet),
            'tenant' => [
                'status' => $tenant?->status,
                'trial_ends_at' => $tenant?->trial_ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function plans(): JsonResponse
    {
        $this->authorize('billing.view');

        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('price')
            ->orderBy('sort_order')
            ->get();

        return $this->ok([
            'plans' => $plans->map(fn (Plan $p) => $this->planArray($p)),
            'gst_rate' => (int) InvoiceSetting::current()->gst_rate,
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $wallet = $this->walletFor($request);
        $transactions = $wallet
            ? $wallet->transactions()->orderByDesc('id')->limit(50)->get()
            : collect();

        return $this->ok([
            'wallet' => $this->walletArray($wallet),
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->uuid,
                'type' => $t->type,
                'amount' => $this->money($t->amount_minor),
                'amount_minor' => $t->amount_minor,
                'balance_after' => $this->money($t->balance_after_minor),
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $invoices = Invoice::orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return $this->ok([
            'invoices' => $invoices->getCollection()->map(fn (Invoice $inv) => $this->invoiceArray($inv)),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Assign / change the tenant's plan. Records the subscription against the
     * chosen plan; payment capture (if the plan is paid) is handled by the
     * gateway integration and is intentionally out of scope here.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,uuid'],
        ]);

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
        $subscription = $this->applyPlan($plan, $request->user()->tenant_id);

        return $this->ok(['subscription' => $this->subscriptionArray($subscription)]);
    }

    /**
     * Start a checkout. Free plans are assigned immediately. Paid plans create a
     * Razorpay order and return the details the frontend needs to open checkout.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate(array_merge(
            ['plan_id' => ['required', 'string', 'exists:plans,uuid']],
            $this->billingRules(),
        ));

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
        [$base, $rate, $tax, $total] = $this->amounts($plan);

        // Free plan — no payment needed, assign and invoice (₹0) right away.
        if ($total <= 0) {
            $subscription = $this->applyPlan($plan, $request->user()->tenant_id);
            $invoice = $this->generateInvoice($subscription, $plan, $data['billing'], $base, $rate, $tax, $total, 'free', null);
            return $this->ok([
                'free' => true,
                'subscription' => $this->subscriptionArray($subscription),
                'invoice' => $this->invoiceArray($invoice),
            ]);
        }

        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (! $key || ! $secret) {
            Log::warning('billing.order: Razorpay not configured');
            return $this->fail('Online payments are not configured. Please contact support.', [], 503);
        }

        Log::info('billing.order: creating Razorpay order', ['plan' => $plan->name, 'total_paise' => $total, 'tenant' => $request->user()->tenant_id]);

        $response = \Illuminate\Support\Facades\Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $total, // paise, incl GST
                'currency' => $plan->currency ?: 'INR',
                'receipt' => 'plan_' . $plan->uuid . '_' . now()->timestamp,
                'notes' => ['plan' => $plan->name, 'tenant_id' => (string) $request->user()->tenant_id],
            ]);

        if ($response->failed()) {
            $msg = data_get($response->json(), 'error.description', 'Could not create the payment order.');
            Log::warning('billing.order: Razorpay failed', ['error' => $msg]);
            return $this->fail('Razorpay: ' . $msg, [], 502);
        }

        Log::info('billing.order: order created', ['order_id' => $response->json('id')]);

        return $this->ok([
            'free' => false,
            'order_id' => $response->json('id'),
            'amount' => $total,
            'subtotal' => $base,
            'tax' => $tax,
            'gst_rate' => $rate,
            'currency' => $plan->currency ?: 'INR',
            'key_id' => $key,
            'plan' => $this->planArray($plan),
        ]);
    }

    /**
     * Verify a completed Razorpay payment and, on success, assign the plan to the
     * tenant. Signature = HMAC-SHA256(order_id|payment_id, secret).
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate(array_merge([
            'plan_id' => ['required', 'string', 'exists:plans,uuid'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ], $this->billingRules()));

        $secret = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], (string) $secret);

        if (! hash_equals($expected, $data['razorpay_signature'])) {
            Log::warning('billing.verify: signature mismatch', ['order_id' => $data['razorpay_order_id']]);
            return $this->fail('Payment verification failed. If money was deducted it will be refunded automatically.', [], 422);
        }

        Log::info('billing.verify: signature valid, applying plan', ['payment_id' => $data['razorpay_payment_id'], 'tenant' => $request->user()->tenant_id]);

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
        [$base, $rate, $tax, $total] = $this->amounts($plan);
        $subscription = $this->applyPlan($plan, $request->user()->tenant_id);
        $invoice = $this->generateInvoice($subscription, $plan, $data['billing'], $base, $rate, $tax, $total, 'paid', $data['razorpay_payment_id']);

        Log::info('billing.verify: plan activated', ['plan' => $plan->name, 'subscription_id' => $subscription->id]);

        return $this->ok([
            'message' => 'Payment successful — your plan is now active.',
            'subscription' => $this->subscriptionArray($subscription),
            'invoice' => $this->invoiceArray($invoice),
        ]);
    }

    /** Validation rules for the customer billing details block. */
    private function billingRules(): array
    {
        return [
            'billing' => ['required', 'array'],
            'billing.name' => ['required', 'string', 'max:255'],
            'billing.email' => ['required', 'email', 'max:255'],
            'billing.phone' => ['required', 'string', 'max:32'],
            'billing.address' => ['required', 'string', 'max:1000'],
            'billing.gstin' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{0:int,1:int,2:int,3:int} [base, gstRate, tax, total] in paise. */
    private function amounts(Plan $plan): array
    {
        $base = (int) round($plan->price * 100);
        $rate = (int) InvoiceSetting::current()->gst_rate;
        $tax = (int) round($base * $rate / 100);
        return [$base, $rate, $tax, $base + $tax];
    }

    /** Create a paid/free invoice snapshotting seller + customer + GST. */
    private function generateInvoice(Subscription $subscription, Plan $plan, array $billing, int $base, int $rate, int $tax, int $total, string $status, ?string $paymentId): Invoice
    {
        $seller = InvoiceSetting::current();
        $number = $this->nextInvoiceNumber($seller->invoice_prefix ?: 'INV');

        return Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'number' => $number,
            'status' => $status === 'free' ? 'paid' : $status,
            'subtotal_minor' => $base,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'currency' => $plan->currency ?: 'INR',
            'gateway' => $status === 'free' ? 'none' : 'razorpay',
            'gateway_invoice_id' => $paymentId,
            'line_items' => [[
                'description' => $plan->name . ' plan (' . ($plan->billing_period === 'yearly' ? '1 year' : '1 month') . ')',
                'amount_minor' => $base,
            ]],
            'meta' => [
                'seller' => $seller->snapshot(),
                'customer' => [
                    'name' => $billing['name'],
                    'email' => $billing['email'],
                    'phone' => $billing['phone'],
                    'address' => $billing['address'],
                    'gstin' => $billing['gstin'] ?? null,
                ],
                'gst_rate' => $rate,
                'plan_name' => $plan->name,
                'billing_period' => $plan->billing_period,
                'period_start' => $subscription->current_period_start?->toDateString(),
                'period_end' => $subscription->current_period_end?->toDateString(),
            ],
            'issued_at' => now(),
            'paid_at' => now(),
        ]);
    }

    /** Sequential, human-readable invoice number: PREFIX-YYYY-000123. */
    private function nextInvoiceNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $count = Invoice::withoutGlobalScopes()->whereYear('created_at', $year)->count() + 1;
        return sprintf('%s-%s-%06d', $prefix, $year, $count);
    }

    /** Create or update the tenant's subscription to point at the given plan. */
    private function applyPlan(Plan $plan, int $tenantId): Subscription
    {
        $attributes = [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth(),
        ];

        $subscription = Subscription::latest('id')->first();
        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $subscription = Subscription::create(array_merge($attributes, ['tenant_id' => $tenantId]));
        }

        return $subscription->load('plan');
    }

    // ---- helpers ----

    private function walletFor(Request $request): ?Wallet
    {
        return Wallet::first(); // tenant-scoped by global scope; one wallet per tenant
    }

    private function walletArray(?Wallet $wallet): array
    {
        return [
            'balance' => $this->money($wallet?->balance_minor ?? 0),
            'balance_minor' => $wallet?->balance_minor ?? 0,
            'reserved' => $this->money($wallet?->reserved_minor ?? 0),
            'currency' => $wallet?->currency ?? 'INR',
            'auto_recharge' => (bool) ($wallet?->auto_recharge ?? false),
        ];
    }

    private function planArray(Plan $p): array
    {
        return [
            'id' => $p->uuid ?? (string) $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'billing_period' => $p->billing_period,
            'price' => (float) $p->price,
            'price_display' => $this->money((int) round($p->price * 100), $p->currency),
            'currency' => $p->currency,
            'trial_days' => $p->trial_days,
            'features' => $p->features ?? [],
            'limits' => $p->limits ?? [],
        ];
    }

    private function subscriptionArray(Subscription $s): array
    {
        return [
            'id' => $s->uuid,
            'status' => $s->status,
            'plan' => $s->plan ? $this->planArray($s->plan) : null,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_start' => $s->current_period_start?->toIso8601String(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
        ];
    }

    /** Full invoice shape for listing / viewing / printing. */
    private function invoiceArray(Invoice $inv): array
    {
        return [
            'id' => $inv->uuid,
            'number' => $inv->number,
            'status' => $inv->status,
            'subtotal' => $this->money($inv->subtotal_minor, $inv->currency),
            'tax' => $this->money($inv->tax_minor, $inv->currency),
            'total' => $this->money($inv->total_minor, $inv->currency),
            'subtotal_minor' => $inv->subtotal_minor,
            'tax_minor' => $inv->tax_minor,
            'total_minor' => $inv->total_minor,
            'currency' => $inv->currency,
            'line_items' => $inv->line_items ?? [],
            'meta' => $inv->meta ?? [],
            'issued_at' => $inv->issued_at?->toIso8601String(),
            'paid_at' => $inv->paid_at?->toIso8601String(),
            'due_at' => $inv->due_at?->toIso8601String(),
        ];
    }

    /** Format minor units (paise) into a currency string like "₹1,250.00". */
    private function money(int $minor, string $currency = 'INR'): string
    {
        $symbol = $currency === 'INR' ? '₹' : ($currency === 'USD' ? '$' : $currency . ' ');
        return $symbol . number_format($minor / 100, 2);
    }
}
