<?php

namespace App\Modules\Billing\Services;

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use Illuminate\Support\Facades\DB;

/**
 * Pay-per-use AI credit engine. Every tenant has one credit wallet that is
 * topped up to its plan's monthly allowance; each AI action costs credits and is
 * recorded in the credit ledger. Pattern: canAfford() -> do work -> deduct().
 */
class CreditService
{
    /** Credit cost per AI action. */
    public const COSTS = [
        'ai_chat' => 1,
        'ai_review_reply' => 1,
        'ai_keyword_gen' => 2,
        'ai_rank_check' => 1,
        'ai_competitor' => 2,
        'ai_audit' => 2,
        'ai_optimize' => 3,
        'ai_media_generate' => 5,
    ];

    /** Default monthly allowance when a plan does not set one. */
    private const DEFAULT_ALLOWANCE = 100;

    public function __construct(private readonly PlanLimitService $plans) {}

    public function cost(string $action): int
    {
        return self::COSTS[$action] ?? 1;
    }

    /** The tenant's allowance from its plan (credits per period). */
    public function allowanceFor(int $tenantId): int
    {
        $plan = $this->plans->planFor($tenantId);
        $v = $plan?->limit('ai_credits');

        return $v === null || $v === '' ? self::DEFAULT_ALLOWANCE : max(0, (int) $v);
    }

    /**
     * Get (or create) the tenant's wallet, applying a monthly reset to the plan
     * allowance when the period has rolled over.
     */
    public function walletFor(int $tenantId): CreditWallet
    {
        $allowance = $this->allowanceFor($tenantId);
        $wallet = CreditWallet::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenantId]);

        if (! $wallet->exists) {
            $wallet->fill(['balance' => $allowance, 'monthly_allowance' => $allowance, 'reset_at' => now()->addMonth()]);
            $wallet->save();
            $this->log($wallet, null, $allowance, 'monthly_reset', 'Initial plan allowance');

            return $wallet;
        }

        // Keep the stored allowance in sync with the current plan.
        if ($wallet->monthly_allowance !== $allowance) {
            $wallet->monthly_allowance = $allowance;
            $wallet->save();
        }

        if ($wallet->reset_at === null || $wallet->reset_at->isPast()) {
            $wallet->balance = $allowance;
            $wallet->reset_at = now()->addMonth();
            $wallet->save();
            $this->log($wallet, null, $allowance, 'monthly_reset', 'Monthly credit reset');
        }

        return $wallet;
    }

    public function balance(int $tenantId): int
    {
        return $this->walletFor($tenantId)->balance;
    }

    public function canAfford(int $tenantId, string $action): bool
    {
        return $this->balance($tenantId) >= $this->cost($action);
    }

    /**
     * Spend credits for an action. Transactional + row-locked so concurrent
     * requests cannot overspend. Returns false (no deduction) when short.
     */
    public function deduct(int $tenantId, ?int $userId, string $action, ?string $description = null): bool
    {
        $cost = $this->cost($action);
        $this->walletFor($tenantId); // ensure exists + monthly reset applied

        return DB::transaction(function () use ($tenantId, $userId, $action, $cost, $description) {
            $wallet = CreditWallet::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if (! $wallet || $wallet->balance < $cost) {
                return false;
            }
            $wallet->decrement('balance', $cost);
            $wallet->refresh();
            $this->log($wallet, $userId, -$cost, $action, $description);

            return true;
        });
    }

    /** Add credits (admin top-up, purchase, bonus). */
    public function add(int $tenantId, int $amount, string $action = 'topup', ?string $description = null, ?int $userId = null): CreditWallet
    {
        $amount = max(0, $amount);
        $this->walletFor($tenantId);

        return DB::transaction(function () use ($tenantId, $amount, $action, $description, $userId) {
            $wallet = CreditWallet::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $wallet->increment('balance', $amount);
            $wallet->refresh();
            $this->log($wallet, $userId, $amount, $action, $description);

            return $wallet;
        });
    }

    /** Recent ledger rows for the tenant. */
    public function ledger(int $tenantId, int $limit = 50)
    {
        return CreditLedger::withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('id')->limit($limit)->get();
    }

    private function log(CreditWallet $wallet, ?int $userId, int $delta, string $action, ?string $description): void
    {
        CreditLedger::withoutGlobalScopes()->create([
            'tenant_id' => $wallet->tenant_id,
            'credit_wallet_id' => $wallet->id,
            'user_id' => $userId,
            'delta' => $delta,
            'balance_after' => $wallet->balance,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
