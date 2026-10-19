<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InvoiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin settings: the seller/company details printed on every invoice.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['settings' => $this->toArray(InvoiceSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'tax_details' => ['nullable', 'string', 'max:255'],
            'invoice_prefix' => ['nullable', 'string', 'max:16'],
            'gst_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $settings = InvoiceSetting::current();
        $settings->update($data);

        return $this->ok(['settings' => $this->toArray($settings->fresh()), 'message' => 'Settings saved.']);
    }

    private function toArray(InvoiceSetting $s): array
    {
        return [
            'company_name' => $s->company_name,
            'address' => $s->address,
            'gstin' => $s->gstin,
            'email' => $s->email,
            'phone' => $s->phone,
            'tax_details' => $s->tax_details,
            'invoice_prefix' => $s->invoice_prefix,
            'gst_rate' => $s->gst_rate,
        ];
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
