<?php

namespace App\Modules\Invoicing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvoice;
use App\Models\InvoiceCustomer;
use App\Models\InvoiceExpense;
use App\Models\InvoiceProfile;
use App\Models\InvoiceService;
use App\Models\InvoiceServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer-invoicing tool (ported from ReviewFlow): invoices, customers,
 * services + categories, expenses, and the per-tenant billing profile. Every
 * model is tenant-scoped via BelongsToTenant, so there is no agency plumbing.
 * Gated by plan feature 'invoicing'.
 */
class InvoicingController extends Controller
{
    // ---- Invoices ---------------------------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        $query = CustomerInvoice::with('customer:id,uuid,name')->orderByDesc('issue_date');
        if ($search = $request->query('q')) {
            $like = '%' . $this->escapeLike($search) . '%';
            $query->where(fn ($q) => $q
                ->where('invoice_number', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)));
        }
        $invoices = $query->get();

        // Reflect overdue status without persisting it on every read.
        $invoices->each(fn (CustomerInvoice $i) => $i->isOverdue() ? $i->status = 'OVERDUE' : null);

        $all = CustomerInvoice::get(['status', 'total', 'paid_at']);
        $stats = [
            'outstanding' => (float) $all->whereIn('status', ['SENT', 'OVERDUE'])->sum('total'),
            'paid_this_month' => (float) $all->where('status', 'PAID')
                ->filter(fn ($i) => $i->paid_at && $i->paid_at->isCurrentMonth())->sum('total'),
            'drafts' => $all->where('status', 'DRAFT')->count(),
        ];

        return $this->ok([
            'invoices' => $invoices->map(fn (CustomerInvoice $i) => [
                'id' => $i->uuid,
                'invoice_number' => $i->invoice_number,
                'customer_name' => $i->customer?->name,
                'issue_date' => $i->issue_date?->toDateString(),
                'due_date' => $i->due_date?->toDateString(),
                'status' => $i->status,
                'total' => (float) $i->total,
            ])->all(),
            'stats' => $stats,
        ]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string', 'exists:invoice_customers,uuid'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.service_id' => ['nullable', 'string', 'exists:invoice_services,uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $customer = InvoiceCustomer::where('uuid', $data['customer_id'])->firstOrFail();
        $tenantId = $request->user()->tenant_id;

        $invoice = DB::transaction(function () use ($data, $customer, $tenantId) {
            $profile = InvoiceProfile::firstOrCreate(['tenant_id' => $tenantId]);
            $number = $profile->invoice_prefix . '-' . str_pad((string) $profile->next_invoice_number, 4, '0', STR_PAD_LEFT);
            $profile->increment('next_invoice_number');

            $subtotal = 0;
            $gstTotal = 0;
            $lines = [];
            foreach ($data['items'] as $item) {
                $lineBase = $item['quantity'] * $item['unit_price'];
                $gstPct = $item['gst_percent'] ?? (float) $profile->default_gst;
                $lineGst = round($lineBase * $gstPct / 100, 2);
                $subtotal += $lineBase;
                $gstTotal += $lineGst;
                $lines[] = [
                    'tenant_id' => $tenantId,
                    'invoice_service_id' => isset($item['service_id'])
                        ? InvoiceService::where('uuid', $item['service_id'])->value('id')
                        : null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'gst_percent' => $gstPct,
                    'amount' => round($lineBase + $lineGst, 2),
                ];
            }

            $invoice = CustomerInvoice::create([
                'invoice_customer_id' => $customer->id,
                'invoice_number' => $number,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'DRAFT',
                'subtotal' => round($subtotal, 2),
                'gst_total' => round($gstTotal, 2),
                'total' => round($subtotal + $gstTotal, 2),
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->items()->createMany($lines);

            return $invoice;
        });

        return $this->ok(['invoice' => $this->invoiceArray($invoice->load('items', 'customer'))], 201);
    }

    public function showInvoice(string $uuid): JsonResponse
    {
        $invoice = CustomerInvoice::with('items', 'customer')->where('uuid', $uuid)->firstOrFail();
        $profile = InvoiceProfile::firstOrCreate(['tenant_id' => $invoice->tenant_id]);

        return $this->ok(['invoice' => $this->invoiceArray($invoice), 'profile' => $profile]);
    }

    public function markSent(string $uuid): JsonResponse
    {
        $invoice = CustomerInvoice::where('uuid', $uuid)->firstOrFail();
        $invoice->update(['status' => 'SENT']);

        return $this->ok(['message' => 'Invoice marked as sent.']);
    }

    public function markPaid(string $uuid): JsonResponse
    {
        $invoice = CustomerInvoice::where('uuid', $uuid)->firstOrFail();
        $invoice->update(['status' => 'PAID', 'paid_at' => now()]);

        return $this->ok(['message' => 'Invoice marked as paid.']);
    }

    public function destroyInvoice(string $uuid): JsonResponse
    {
        $invoice = CustomerInvoice::where('uuid', $uuid)->firstOrFail();
        abort_unless($invoice->status === 'DRAFT', 403, 'Only draft invoices can be deleted.');
        $invoice->delete();

        return $this->ok(['message' => 'Invoice deleted.']);
    }

    // ---- Customers --------------------------------------------------------

    public function customers(Request $request): JsonResponse
    {
        $customers = InvoiceCustomer::withCount('invoices')->orderBy('name')->get();

        return $this->ok(['customers' => $customers->map(fn (InvoiceCustomer $c) => [
            'id' => $c->uuid,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'gstin' => $c->gstin,
            'billing_address' => $c->billing_address,
            'invoices_count' => $c->invoices_count,
        ])->all()]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = InvoiceCustomer::create($data);

        return $this->ok(['customer' => ['id' => $customer->uuid, 'name' => $customer->name]], 201);
    }

    public function updateCustomer(Request $request, string $uuid): JsonResponse
    {
        $customer = InvoiceCustomer::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'string', 'max:255'],
        ]);
        $customer->update($data);

        return $this->ok(['message' => 'Customer updated.']);
    }

    public function destroyCustomer(string $uuid): JsonResponse
    {
        InvoiceCustomer::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Customer deleted.']);
    }

    // ---- Services ---------------------------------------------------------

    public function services(Request $request): JsonResponse
    {
        $services = InvoiceService::with('category:id,uuid,name')->orderBy('name')->get();

        return $this->ok(['services' => $services->map(fn (InvoiceService $s) => [
            'id' => $s->uuid,
            'name' => $s->name,
            'price' => (float) $s->price,
            'gst_percent' => (float) $s->gst_percent,
            'status' => $s->status,
            'category' => $s->category?->name,
            'category_id' => $s->category?->uuid,
        ])->all()]);
    }

    public function storeService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'exists:invoice_service_categories,uuid'],
            'price' => ['required', 'numeric', 'min:0'],
            'gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $service = InvoiceService::create([
            'name' => $data['name'],
            'price' => $data['price'],
            'gst_percent' => $data['gst_percent'] ?? 18,
            'status' => 'ACTIVE',
            'invoice_service_category_id' => isset($data['category_id'])
                ? InvoiceServiceCategory::where('uuid', $data['category_id'])->value('id')
                : null,
        ]);

        return $this->ok(['service' => ['id' => $service->uuid, 'name' => $service->name]], 201);
    }

    public function updateService(Request $request, string $uuid): JsonResponse
    {
        $service = InvoiceService::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'string', 'exists:invoice_service_categories,uuid'],
            'price' => ['required', 'numeric', 'min:0'],
            'gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $service->update([
            'name' => $data['name'],
            'price' => $data['price'],
            'gst_percent' => $data['gst_percent'] ?? $service->gst_percent,
            'status' => $data['status'],
            'invoice_service_category_id' => isset($data['category_id'])
                ? InvoiceServiceCategory::where('uuid', $data['category_id'])->value('id')
                : null,
        ]);

        return $this->ok(['message' => 'Service updated.']);
    }

    public function destroyService(string $uuid): JsonResponse
    {
        InvoiceService::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Service deleted.']);
    }

    // ---- Categories -------------------------------------------------------

    public function categories(Request $request): JsonResponse
    {
        $categories = InvoiceServiceCategory::withCount('services')->orderBy('name')->get();

        return $this->ok(['categories' => $categories->map(fn (InvoiceServiceCategory $c) => [
            'id' => $c->uuid,
            'name' => $c->name,
            'services_count' => $c->services_count,
        ])->all()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $category = InvoiceServiceCategory::create($data);

        return $this->ok(['category' => ['id' => $category->uuid, 'name' => $category->name]], 201);
    }

    public function destroyCategory(string $uuid): JsonResponse
    {
        InvoiceServiceCategory::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Category deleted.']);
    }

    // ---- Expenses ---------------------------------------------------------

    public function expenses(Request $request): JsonResponse
    {
        $query = InvoiceExpense::orderByDesc('date');
        if ($month = $request->query('month')) { // "YYYY-MM"
            $query->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2));
        }
        $expenses = $query->get();

        $thisMonth = InvoiceExpense::whereYear('date', now()->year)->whereMonth('date', now()->month)->get(['amount']);

        return $this->ok([
            'expenses' => $expenses->map(fn (InvoiceExpense $e) => [
                'id' => $e->uuid,
                'date' => $e->date?->toDateString(),
                'description' => $e->description,
                'category' => $e->category,
                'method' => $e->method,
                'amount' => (float) $e->amount,
            ])->all(),
            'stats' => [
                'spent_this_month' => (float) $thisMonth->sum('amount'),
                'count' => $expenses->count(),
            ],
        ]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'method' => ['required', Rule::in(InvoiceExpense::METHODS)],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $expense = InvoiceExpense::create($data);

        return $this->ok(['expense' => ['id' => $expense->uuid]], 201);
    }

    public function destroyExpense(string $uuid): JsonResponse
    {
        InvoiceExpense::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Expense deleted.']);
    }

    // ---- Billing profile --------------------------------------------------

    public function profile(Request $request): JsonResponse
    {
        $profile = InvoiceProfile::firstOrCreate(['tenant_id' => $request->user()->tenant_id]);

        return $this->ok(['profile' => $profile]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:60'],
            'logo' => ['nullable', 'string'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:50'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'invoice_prefix' => ['required', 'string', 'max:10'],
            'default_gst' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'round_total' => ['nullable', 'boolean'],
        ]);
        $data['round_total'] = $request->boolean('round_total');

        $profile = InvoiceProfile::firstOrCreate(['tenant_id' => $request->user()->tenant_id]);
        $profile->update($data);

        return $this->ok(['profile' => $profile->fresh()]);
    }

    private function invoiceArray(CustomerInvoice $i): array
    {
        return [
            'id' => $i->uuid,
            'invoice_number' => $i->invoice_number,
            'customer' => $i->customer ? ['id' => $i->customer->uuid, 'name' => $i->customer->name] : null,
            'issue_date' => $i->issue_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'status' => $i->status,
            'subtotal' => (float) $i->subtotal,
            'gst_total' => (float) $i->gst_total,
            'total' => (float) $i->total,
            'notes' => $i->notes,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'items' => $i->relationLoaded('items') ? $i->items->map(fn (\App\Models\CustomerInvoiceItem $it) => [
                'id' => $it->uuid,
                'description' => $it->description,
                'quantity' => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'gst_percent' => (float) $it->gst_percent,
                'amount' => (float) $it->amount,
            ])->all() : [],
        ];
    }
}
