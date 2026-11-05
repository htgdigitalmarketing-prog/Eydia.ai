<?php

namespace App\Modules\Billing\Services;

use App\Models\Plan;
use App\Support\BillingCycle;
use App\Support\Gst;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay AUTOPAY (recurring subscriptions). Creates a Razorpay Plan per
 * (Eydia plan × billing cycle) on demand — caching its id on the plan — then
 * opens a Razorpay Subscription the customer authorises once (a UPI/card
 * mandate). Razorpay then auto-charges every cycle; renewals arrive on the
 * `subscription.charged` webhook.
 *
 * Needs RAZORPAY_KEY / RAZORPAY_SECRET, and "Subscriptions" enabled on the
 * Razorpay account.
 */
class RazorpaySubscriptionService
{
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function configured(): bool
    {
        return (bool) (config('services.razorpay.key') && config('services.razorpay.secret'));
    }

    /**
     * Ensure a Razorpay Plan exists for this plan+cycle and return its id.
     * The id is cached in plan.meta['razorpay_plans'][cycle].
     */
    public function ensureRazorpayPlan(Plan $plan, string $cycle): ?string
    {
        $cycle = BillingCycle::normalize($cycle);
        $meta = $plan->meta ?? [];
        $cached = $meta['razorpay_plans'][$cycle] ?? null;
        if ($cached) {
            return $cached;
        }

        $c = BillingCycle::config($cycle);
        // Razorpay charges the GST-inclusive amount (paise) each interval.
        $price = BillingCycle::price((float) $plan->price, $cycle);
        $amount = Gst::inclusivePaise($price, 0)['total']; // price already final

        $res = $this->client()->post('https://api.razorpay.com/v1/plans', [
            'period' => $c['period'],
            'interval' => $c['interval'],
            'item' => [
                'name' => $plan->name . ' — ' . $c['label'],
                'amount' => $amount,
                'currency' => $plan->currency ?: 'INR',
                'description' => $plan->name . ' plan billed ' . strtolower($c['label']),
            ],
            'notes' => ['plan_uuid' => $plan->uuid, 'cycle' => $cycle],
        ]);

        if ($res->failed()) {
            $this->lastError = 'Razorpay: ' . data_get($res->json(), 'error.description', 'could not create plan.');
            Log::warning('razorpay.subscription: ensure plan failed', ['body' => $res->json()]);

            return null;
        }

        $id = $res->json('id');
        $meta['razorpay_plans'][$cycle] = $id;
        $plan->forceFill(['meta' => $meta])->save();

        return $id;
    }

    /**
     * Create a Razorpay Subscription (autopay). Returns the subscription id +
     * short_url, or null on failure.
     *
     * @return array{id:string, short_url:?string, plan_id:string}|null
     */
    public function createSubscription(Plan $plan, string $cycle, int $tenantId, array $billing): ?array
    {
        $this->lastError = null;
        if (! $this->configured()) {
            $this->lastError = 'Online payments are not configured (RAZORPAY_KEY/RAZORPAY_SECRET).';

            return null;
        }

        $razorpayPlanId = $this->ensureRazorpayPlan($plan, $cycle);
        if (! $razorpayPlanId) {
            return null;
        }

        $c = BillingCycle::config($cycle);
        $res = $this->client()->post('https://api.razorpay.com/v1/subscriptions', [
            'plan_id' => $razorpayPlanId,
            'total_count' => $c['total_count'], // how many cycles the mandate runs
            'customer_notify' => 1,
            'notes' => [
                'tenant_id' => (string) $tenantId,
                'plan_uuid' => $plan->uuid,
                'cycle' => $cycle,
                'customer_name' => $billing['name'] ?? '',
                'customer_email' => $billing['email'] ?? '',
            ],
        ]);

        if ($res->failed()) {
            $this->lastError = 'Razorpay: ' . data_get($res->json(), 'error.description', 'could not start the subscription.');
            Log::warning('razorpay.subscription: create failed', ['body' => $res->json()]);

            return null;
        }

        return [
            'id' => $res->json('id'),
            'short_url' => $res->json('short_url'),
            'plan_id' => $razorpayPlanId,
        ];
    }

    /** Cancel a Razorpay subscription (at cycle end by default). */
    public function cancelSubscription(string $subscriptionId, bool $atCycleEnd = true): bool
    {
        $res = $this->client()->post("https://api.razorpay.com/v1/subscriptions/{$subscriptionId}/cancel", [
            'cancel_at_cycle_end' => $atCycleEnd ? 1 : 0,
        ]);
        if ($res->failed()) {
            $this->lastError = 'Razorpay: ' . data_get($res->json(), 'error.description', 'could not cancel.');

            return false;
        }

        return true;
    }

    /** Verify the signature Razorpay returns after the customer authorises autopay. */
    public function verifySignature(string $subscriptionId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.secret');
        // For subscriptions Razorpay signs payment_id|subscription_id.
        $expected = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $secret);

        return hash_equals($expected, $signature);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.razorpay.key'),
            (string) config('services.razorpay.secret'),
        )->acceptJson();
    }
}
