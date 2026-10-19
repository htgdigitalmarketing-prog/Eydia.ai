<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.razorpay.webhook_secret');
        if (! $secret) {
            Log::warning('razorpay.webhook: RAZORPAY_WEBHOOK_SECRET not set, rejecting');
            return response()->json(['status' => 'ignored'], 200);
        }

        $signature = $request->header('X-Razorpay-Signature');
        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if (! $signature || ! hash_equals($expected, $signature)) {
            Log::warning('razorpay.webhook: signature mismatch');
            return response()->json(['status' => 'invalid'], 400);
        }

        $event = $request->input('event');
        $payload = $request->input('payload');

        Log::info('razorpay.webhook: received', ['event' => $event]);

        if ($event === 'payment.captured') {
            $this->handlePaymentCaptured($payload);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function handlePaymentCaptured(array $payload): void
    {
        $payment = $payload['payment']['entity'] ?? [];
        $orderId = $payment['order_id'] ?? null;
        $paymentId = $payment['id'] ?? null;
        $notes = $payment['notes'] ?? [];
        $tenantId = $notes['tenant_id'] ?? null;
        $planName = $notes['plan'] ?? null;

        if (! $orderId || ! $paymentId || ! $tenantId) {
            Log::warning('razorpay.webhook: payment.captured missing fields', compact('orderId', 'paymentId', 'tenantId'));
            return;
        }

        $existingInvoice = Invoice::withoutGlobalScopes()
            ->where('gateway_invoice_id', $paymentId)
            ->first();

        if ($existingInvoice) {
            Log::info('razorpay.webhook: payment already processed', ['payment_id' => $paymentId]);
            return;
        }

        $receipt = $payment['receipt'] ?? '';
        $planUuid = str_starts_with($receipt, 'plan_') ? explode('_', $receipt)[1] ?? null : null;

        $plan = null;
        if ($planUuid) {
            $plan = Plan::where('uuid', $planUuid)->where('is_active', true)->first();
        }
        if (! $plan && $planName) {
            $plan = Plan::where('name', $planName)->where('is_active', true)->first();
        }

        if (! $plan) {
            Log::warning('razorpay.webhook: could not find plan', compact('planUuid', 'planName'));
            return;
        }

        $tenantId = (int) $tenantId;

        $attributes = [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth(),
        ];

        $subscription = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();

        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $subscription = Subscription::create(array_merge($attributes, ['tenant_id' => $tenantId]));
        }

        $base = (int) round($plan->price * 100);
        $rate = (int) InvoiceSetting::current()->gst_rate;
        $tax = (int) round($base * $rate / 100);
        $total = $base + $tax;

        $year = now()->format('Y');
        $prefix = InvoiceSetting::current()->invoice_prefix ?: 'INV';
        $count = Invoice::withoutGlobalScopes()->whereYear('created_at', $year)->count() + 1;
        $number = sprintf('%s-%s-%06d', $prefix, $year, $count);

        Invoice::create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'number' => $number,
            'status' => 'paid',
            'subtotal_minor' => $base,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'currency' => $plan->currency ?: 'INR',
            'gateway' => 'razorpay',
            'gateway_invoice_id' => $paymentId,
            'line_items' => [[
                'description' => $plan->name . ' plan (' . ($plan->billing_period === 'yearly' ? '1 year' : '1 month') . ')',
                'amount_minor' => $base,
            ]],
            'meta' => [
                'source' => 'webhook',
                'razorpay_order_id' => $orderId,
                'gst_rate' => $rate,
                'plan_name' => $plan->name,
                'billing_period' => $plan->billing_period,
                'period_start' => $subscription->current_period_start?->toDateString(),
                'period_end' => $subscription->current_period_end?->toDateString(),
            ],
            'issued_at' => now(),
            'paid_at' => now(),
        ]);

        Log::info('razorpay.webhook: plan activated via webhook', [
            'tenant_id' => $tenantId,
            'plan' => $plan->name,
            'payment_id' => $paymentId,
        ]);
    }
}
