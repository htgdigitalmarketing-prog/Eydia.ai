<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CreditLedger;
use App\Modules\Billing\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI credit balance, cost table and usage ledger for the signed-in tenant.
 */
class CreditController extends Controller
{
    public function __construct(private readonly CreditService $credits) {}

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
