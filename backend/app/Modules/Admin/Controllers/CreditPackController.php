<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CreditPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin management of buyable AI-credit packs. Price is GST-inclusive;
 * the base/GST split is derived for display. Pausing (is_active=false) hides a
 * pack from the client "Buy Credits" page without deleting it.
 */
class CreditPackController extends Controller
{
    public function index(Request $request): JsonResponse
    {

        return $this->ok([
            'packs' => CreditPack::orderBy('sort_order')->orderBy('price')->get()->map(fn (CreditPack $p) => $this->arr($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $pack = CreditPack::create($this->validated($request));

        return $this->ok(['pack' => $this->arr($pack)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $pack = CreditPack::where('uuid', $uuid)->firstOrFail();
        $pack->update($this->validated($request));

        return $this->ok(['pack' => $this->arr($pack->fresh())]);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $pack = CreditPack::where('uuid', $uuid)->firstOrFail();
        $pack->update(['is_active' => ! $pack->is_active]);

        return $this->ok(['pack' => $this->arr($pack->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        CreditPack::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Credit pack deleted.']);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'credits' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'integer', 'min:0', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function arr(CreditPack $p): array
    {
        $g = $p->gstBreakdown();

        return [
            'id' => $p->uuid,
            'name' => $p->name,
            'credits' => $p->credits,
            'price' => (float) $p->price,
            'gst_rate' => $p->gst_rate,
            'base' => $g['base'],
            'gst' => $g['tax'],
            'sort_order' => $p->sort_order,
            'is_active' => $p->is_active,
        ];
    }
}
