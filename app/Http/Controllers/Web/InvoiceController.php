<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->input('q'));
        $status = (string) $request->input('status');
        $invoices = Invoice::query()
            ->with('user')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where(function ($search) use ($keyword) {
                $search->where('number', 'like', "%$keyword%")
                    ->orWhere('id', $keyword)
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%$keyword%")
                        ->orWhere('email', 'like', "%$keyword%"));
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'keyword' => $keyword,
            'status' => $status,
            'customers' => User::query()->where('role', 'client')->where('status', 'Active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('role', 'client')->where('status', 'Active')
            )],
            'description' => ['required', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'min:0.01', 'max:999999999999.99'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $data['idempotency_key'] = hash('sha256', "manual-invoice\0{$data['idempotency_key']}");

        [$invoice, $created] = DB::transaction(function () use ($data, $request) {
            User::query()
                ->where('role', 'client')
                ->where('status', 'Active')
                ->lockForUpdate()
                ->findOrFail($data['user_id']);
            $existing = Invoice::query()
                ->where('user_id', $data['user_id'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return [$existing, false];
            }

            $invoice = Invoice::create([
                'user_id' => $data['user_id'],
                'number' => 'KJ'.now()->format('Ymd').strtoupper((string) Str::ulid()),
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'Unpaid',
                'subtotal' => $data['amount'],
                'total' => $data['amount'],
                'currency' => (string) config('kjaiu.currency.code', 'CNY'),
                'due_at' => $data['due_at'],
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->items()->create([
                'type' => 'custom',
                'description' => $data['description'],
                'amount' => $data['amount'],
            ]);
            AuditLog::record(
                $request,
                'invoice.created',
                $invoice,
                null,
                $invoice->only(['user_id', 'total', 'due_at']),
            );

            return [$invoice, true];
        });

        return redirect()->route('admin.invoices.show', $invoice)
            ->with('success', $created ? '账单已开具' : '该开账请求已处理');
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['user', 'items.service', 'transactions', 'order.items']);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'gateways' => PaymentGateway::query()
                ->where('is_active', true)
                ->where('name', '!=', 'Credit')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function pay(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $gateways = PaymentGateway::query()
            ->where('is_active', true)
            ->where('name', '!=', 'Credit')
            ->pluck('name')
            ->push('Cash')
            ->all();
        $data = $request->validate([
            'gateway' => ['required', 'string', 'max:64', Rule::in($gateways)],
            'transaction_number' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $changed = DB::transaction(function () use ($billing, $data, $invoice, $request) {
                $changed = false;
                $paid = $billing->recordPayment(
                    $invoice,
                    $data['gateway'],
                    $data['transaction_number'] ?? null,
                    $changed,
                );
                if ($changed) {
                    AuditLog::record($request, 'invoice.paid', $paid, ['status' => 'Unpaid'], [
                        'status' => 'Paid',
                        'gateway' => $data['gateway'],
                    ]);
                }

                return $changed;
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', $changed ? '入账成功' : '该账单已入账');
    }

    public function cancel(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $changed = DB::transaction(function () use ($billing, $invoice, $request) {
                $changed = false;
                $cancelled = $billing->cancelInvoice($invoice, $changed);
                if ($changed) {
                    AuditLog::record(
                        $request,
                        'invoice.cancelled',
                        $cancelled,
                        ['status' => 'Unpaid'],
                        ['status' => 'Cancelled'],
                    );
                }

                return $changed;
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', $changed ? '账单已取消，预占库存已释放' : '该账单已取消');
    }

    public function updateStatus(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['Paid', 'Cancelled'])],
            'gateway' => ['nullable', 'string', 'max:64'],
            'transaction_number' => ['nullable', 'string', 'max:191'],
        ]);
        if ($invoice->status !== 'Unpaid') {
            throw ValidationException::withMessages(['status' => '只有待支付账单可以修改状态。']);
        }

        if ($data['status'] === 'Cancelled') {
            return $this->cancel($request, $invoice, $billing);
        }
        if (blank($data['gateway'] ?? null)) {
            throw ValidationException::withMessages(['gateway' => '标记为已支付时必须选择收款方式。']);
        }
        $request->merge(['gateway' => $data['gateway']]);

        return $this->pay($request, $invoice, $billing);
    }
}
