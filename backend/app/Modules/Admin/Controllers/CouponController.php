<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super-admin coupon management. Coupons discount on top of the cycle discount. */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['coupons' => Coupon::orderByDesc('id')->get()->map(fn (Coupon $c) => $this->toArray($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $coupon = Coupon::create($this->validated($request));

        return $this->ok(['coupon' => $this->toArray($coupon)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $coupon = Coupon::where('uuid', $uuid)->firstOrFail();
        $coupon->update($this->validated($request, $coupon->id));

        return $this->ok(['coupon' => $this->toArray($coupon->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        Coupon::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Coupon deleted.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($ignoreId)],
            'type' => ['required', Rule::in(['percent', 'flat'])],
            'value' => ['required', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'cycles' => ['nullable', 'array'],
            'cycles.*' => ['string'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function toArray(Coupon $c): array
    {
        return [
            'id' => $c->uuid,
            'code' => $c->code,
            'type' => $c->type,
            'value' => (float) $c->value,
            'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'min_amount' => $c->min_amount !== null ? (float) $c->min_amount : null,
            'max_uses' => $c->max_uses,
            'used_count' => $c->used_count,
            'cycles' => $c->cycles,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'expires_at' => $c->expires_at?->toIso8601String(),
            'is_active' => (bool) $c->is_active,
            'live' => $c->isLive(),
        ];
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
